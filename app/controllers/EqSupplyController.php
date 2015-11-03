<?php

class EqSupplyController extends EquipController {

	public function getSupplyTreeNodes() {
		$parentId = Input::get('id');
		$initNodeId = Input::get('initId');
		$supplyNodes = EqSupplyManagerNode::find($parentId === '#' ? $initNodeId : $parentId)->children;

		$nodes = array();

		foreach ($supplyNodes as $supNode) {
			if ($supNode->is_selectable == 1) {
				$nodes[] = array(
					'id' => $supNode->id,
					'text' => $supNode->node_name,
					'children' => $supNode->is_terminal?array():true,
					'li_attr' => array(
						'data-full-name' => $supNode->full_name,
						'data-selectable' => $supNode->is_selectable
						)
				);
			} else {
				$nodes[] = array(
					'id' => $supNode->id,
					'text' => $supNode->node_name,
					'children' => $supNode->is_terminal?array():true,
					'li_attr' => array(
						'data-full-name' => $supNode->full_name,
						'data-selectable' => $supNode->is_selectable
						),
					'state' => array(
							'disabled'=> true
						)
				);
			}

		}
		return $nodes;
	}

	public function getClassifiers(){
		$itemId = Input::get('item_id');
		$inventories = EqInventory::where('item_id','=',$itemId)->get();
		$res = array();
		if(sizeof($inventories)!==0){
			$options = '';
			foreach ($inventories as $i) {
				$options = $options.'<option value="'.$i->id.'">'.$i->manufacturer.' ('.$i->acq_date.')</option>';
			}
			$res['body'] = $options;
			$res['code'] = 1;
			return $res;
		} else {
			$res['code'] = 0;
			$res['body'] = "보유한 장비가 없어 보급할 수 없습니다.";
			return $res;
		}
	}
	/**
	 * Display a listing of the resource.
	 *
	 * @return Response
	 */
	public function index()
	{
		$start = Input::get('start');
		$end = Input::get('end');
		$user = Sentry::getUser();
		$nodeId = $user->supplyNode->id;

		$validator = Validator::make(Input::all(), array(
				'start'=>'date',
				'end'=>'date'
			));

		if ($validator->fails()) {
			return App::abort(400);
		}

		if (!$start) {
			$start = date('Y-m-d', strtotime('-1 year'));
		}

		if (!$end) {
			$end = date('Y-m-d');
		}

		$itemName = Input::get('item_name');

		$query = EqItemSupplySet::where('supplied_date', '>=', $start)->where('supplied_date', '<=', $end)->where('is_closed','=',0);

		if ($itemName) {
			$query->whereHas('item', function($q) use($itemName) {
				$q->whereHas('code', function($qry) use($itemName) {
					$qry->where('title','like',"%".$itemName."%");
				});
			});
		}
		$supplies = $query->whereHas('node', function($q) use($user){
			$q->where('full_path','like',$user->supplyNode->full_path.'%')->where('is_selectable','=',1);
		})->orderBy('supplied_date','DESC')->paginate(15);

		$items = EqItem::where('is_active','=',1)->whereHas('inventories', function($q) use ($nodeId) {
			$q->where('node_id','=',$nodeId);//->where('acquired_date','>=',date("Y"));
		})->get();

        return View::make('equip.supplies-index', get_defined_vars());
	}

	/**
	 * Show the form for creating a new resource.
	 *
	 * @return Response
	 */
	public function create()
	{
		$itemId = Input::get('item');
		if($itemId==0){
			Session::flash('message', '보유중인 장비가 없어 보급할 수 없습니다.');
			return Redirect::back();
		}
		$user = Sentry::getUser();
		$userNode = $user->supplyNode;
		$lowerNodes = $userNode->managedChildren;
		$data = array();

		$types = EqItemType::where('item_id','=',$itemId)->get();

		$data['types'] = $types;
		$data['mode'] = 'create';
		$data['item'] = EqItem::find($itemId);
		$data['userNode'] = $userNode;
		$data['lowerNodes'] = $lowerNodes;
		$invSum = 0;
		foreach ($types as $t) {
			$inv[$t->id] = EqInventoryData::whereHas('parentSet', function($q) use($userNode){
				$q->where('node_id','=',$userNode->id);
			})->where('item_type_id','=',$t->id)->first()->count;
			$invSum += $inv[$t->id];
		}

		$data['inv'] = $inv;
		$data['invSum'] = $invSum;

        return View::make('equip.supplies-create',$data);
	}

	/**
	 * Store a newly created resource in storage.
	 *
	 * @return Response
	 */

	//장비보급
	public function store()
	{
		$data = Input::all();
		$user = Sentry::getUser();
		$userNode = $user->supplyNode;
		$nodes = $userNode->managedChildren;
		$types = EqItemType::where('item_id','=',$data['item_id'])->get();

		//현재 보유중인 사이즈별 수량을 holdingNum[type_id]에 저장한다.
		foreach ($types as $t) {
			$holdingNum[$t->id] = EqInventoryData::whereHas('parentSet', function($q) use ($user) {
				$q->where('node_id','=',$user->supplyNode->id);
			})->where('item_type_id','=',$t->id)->first()->count;
		}

		DB::beginTransaction();

		$supplySet = new EqItemSupplySet;
		$supplySet->item_id = $data['item_id'];
		$supplySet->creator_id = $user->id;
		$supplySet->from_node_id = $user->supplyNode->id;
		$supplySet->supplied_date = $data['supply_date'];

		if (!$supplySet->save()) {
			return App::abort(500);
		}

		foreach ($nodes as $node) {
			$countName = 'count_';
			$countNameNode = $countName.$node->id.'_';

			// 보급하는 노드의 인벤토리 - $supplyInvSet
			$supplyInvSet = EqInventorySet::where('item_id','=',$data['item_id'])->where('node_id','=',$userNode->id)->first();
			// 보급받는 노드의 인벤토리 - $receiveInvSet
			$receiveInvSet = EqInventorySet::where('item_id','=',$data['item_id'])->where('node_id','=',$node->id)->first();

			if($receiveInvSet == null) {
				// 보급받는 노드에서 이 아이템을 기존에 보유한 적이 없는 경우
				$receiveInvSet = new EqInventorySet;
				$receiveInvSet->item_id = $data['item_id'];
				$receiveInvSet->node_id = $node->id;
				if (!$receiveInvSet->save()) {
					return App::abort(500);
				}
				// 이 아이템에 대한 캐시를 만들어주고 0으로 초기화한다.
				try {
					$this->service->makeCache($node->id);
				} catch (Exception $e) {
					return Redirect::to('equips/supplies')->with('message', $e->getMessage() );
				}

				foreach ($types as $type) {
					$typeId = $type->id;
					$countName = $countNameNode.$typeId;
					// 보급하는 노드에서 보유수량을 줄인다
					$supply = new EqItemSupply;
					$supply->supply_set_id = $supplySet->id;
					$supply->item_type_id = $type->id;
					$supply->count = $data[$countName];
					$supply->to_node_id = $node->id;

					if (!$supply->save()) {
						return App::abort(500);
					}

					$supplyInvData = EqInventoryData::where('inventory_set_id','=',$supplyInvSet->id)->where('item_type_id','=',$typeId)->first();
					try {
						$this->service->inventoryWithdraw($supplyInvData, $data[$countName]);
					} catch (Exception $e) {
						return Redirect::to('equips/supplies')->with('message', $e->getMessage() );
					}
					// 보급받는 노드에서 보유수량을 늘린다
					$invData = new EqInventoryData;
					$invData->inventory_set_id = $receiveInvSet->id;
					$invData->item_type_id = $type->id;
					try {
						$this->service->inventorySupply($invData, $data[$countName]);
					} catch (Exception $e) {
						return Redirect::to('equips/supplies')->with('message', $e->getMessage() );
					}
				}
			} else {
				// 보급받는 노드에서 기존에 그 아이템을 보유한 경우
				foreach ($types as $type) {
					$typeId = $type->id;
					$countName = $countNameNode.$typeId;
					$supply = new EqItemSupply;
					$supply->supply_set_id = $supplySet->id;
					$supply->item_type_id = $type->id;
					$supply->count = $data[$countName];
					$supply->to_node_id = $node->id;

					if (!$supply->save()) {
						return App::abort(500);
					}
					// 보급하는 노드에서 보유수량을 줄인다
					$supplyInvData = EqInventoryData::where('inventory_set_id','=',$supplyInvSet->id)->where('item_type_id','=',$typeId)->first();
					try {
						$this->service->inventoryWithdraw($supplyInvData, $data[$countName]);
					} catch (Exception $e) {
						return Redirect::to('equips/supplies')->with('message', $e->getMessage() );
					}
					// 보급받는 노드에서 보유수량을 늘린다.
					$receiveInvData = EqInventoryData::where('inventory_set_id','=',$receiveInvSet->id)->where('item_type_id','=',$typeId)->first();
					try {
						$this->service->inventorySupply($receiveInvData, $data[$countName]);
					} catch (Exception $e) {
						return Redirect::to('equips/supplies')->with('message', $e->getMessage() );
					}
				}
			}
		}
		//사이즈별 보급 수량을 계산하여 보유수량보다 적으면 빠꾸먹인다.
		DB::commit();

		Session::flash('message', '저장되었습니다.');
		return Redirect::to('equips/supplies');
	}

	/**
	 * Display the specified resource.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function show($id)
	{
		$user = Sentry::getUser();
		$userNode = $user->supplyNode;
		$data = array();
		$supply = EqItemSupplySet::find($id);

		$types = EqItemType::where('item_id','=',$supply->item->id)->get();
		$lowerNodes = $supply->managedChildren;
		$count = array();

		$data['types'] = $types;
		$data['supply'] = $supply;
		$data['item'] = $supply->item;
		$data['lowerNodes'] = $lowerNodes;

		foreach ($lowerNodes as $n) {
			$nodeSupplies = EqItemSupply::where('to_node_id','=',$n->id)->where('supply_set_id','=',$supply->id)->get();

			// 이 부분은 보급 이후에 node 구조에 변동이 생긴 경우 이전에 입력되지 않은 부분은 보급 수량이 0인 것으로 나오게 하는 부분
			if (sizeof($nodeSupplies)==0) {
				foreach ($types as $t) {
					$count[$n->id][$t->id] = '';
				}
			} else {
				foreach ($nodeSupplies as $s) {
					if(!$s->count == 0){
						$count[$n->id][$s->item_type_id] = $s->count;
					} else {
						$count[$n->id][$s->item_type_id] = '';
					}
				}
			}
		}

		$data['count'] = $count;
        return View::make('equip.supplies-show', $data);
	}

	/**
	 * Show the form for editing the specified resource.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function edit($id)
	{
		$user = Sentry::getUser();
		$supply = EqItemSupplySet::find($id);
		$item = $supply->item;
		$types = $item->types;
		$userNode = $user->supplyNode;
		$lowerNodes = $userNode->children;
		$mode = 'update';

		foreach ($lowerNodes as $n) {
			foreach ($types as $t) {
				$count[$n->id][$t->id] = EqItemSupply::where('supply_set_id','=',$id)->where('to_node_id','=',$n->id)->where('item_type_id','=',$t->id)->first()->count;
			}
		}

        return View::make('equip.supplies-create',get_defined_vars());
	}

	/**
	 * Update the specified resource in storage.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function update($id)
	{

	}

	/**
	 * Remove the specified resource from storage.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function destroy($id){
		$s = EqItemSupplySet::find($id);

		$result = $this->service->deleteSupplySet($id);

		if ($result === 1) {
			Session::flash('message', '보급이 취소되었습니다.');
			return Redirect::back();
		} else {
			Session::flash('message', '보급 취소중 오류가 발생했습니다.');
			return Redirect::back();
		}
	}

}
