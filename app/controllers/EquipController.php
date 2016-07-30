<?php

class EquipController extends BaseController {
	protected $service;

	public function __construct() {
		$this->service = new EqService;
	}

	/**
	 * 장비관리의 초기 페이지.
	 * @return type
	 */
	public function index() {
		$user = Sentry::getUser();
		$userNode = $user->supplySet->node;

		$inbounds = EqConvertSet::where('target_node_id','=',$userNode->id)->take(4)->get();
		$outbounds = EqConvertSet::where('from_node_id','=',$userNode->id)->take(4)->get();

		$query = EqItemSupplySet::where('is_closed','=',0)->where('from_node_id','=',$userNode->id);
		$supplies = $query->paginate(15);

		$surveys = EqItemSurvey::where('node_id','=',$userNode->id)->where('is_closed','=',0)->take(4)->get();
		if ($userNode->type_code === 'D001') {
			$toResponses = EqItemSurvey::where('node_id','=',0)->where('is_closed','=',0)->get();
		} else {
			$toResponses = EqItemSurvey::where('node_id','=',$userNode->managedParent->id)->where('is_closed','=',0)->whereHas('datas', function($q) use($userNode){
											$q->where('target_node_id','=',$userNode->id)->where('count','<>',0);
										})->take(4)->get();
		}
		if (!Cache::has('is_cached_'.$userNode->id)) {
			// 기존 캐시가 없는 경우 item별 합계 캐시를 만들어준다.
			$this->service->makeCache($userNode->id);
		}
		return View::make('equip.dashboard', get_defined_vars());
	}

	// Item 지정해서 캐시 생성해주기

	public function makeCache($itemId, $nodeId) {
		$invSet = EqInventorySet::where('node_id','=',$nodeId)->where('item_id','=',$itemId)->first();
		if ($invSet !== null) {
			$countSum = EqInventoryData::where('inventory_set_id','=',$invSet->id)->get()->sum('count');
			$wreckedSum = EqInventoryData::where('inventory_set_id','=',$invSet->id)->get()->sum('wrecked');

			Cache::forever('avail_sum_'.$nodeId.'_'.$itemId, $countSum-$wreckedSum);
			Cache::forever('wrecked_sum_'.$nodeId.'_'.$itemId, $wreckedSum);
		} else {
			Cache::forever('avail_sum_'.$nodeId.'_'.$itemId, 0);
			Cache::forever('wrecked_sum_'.$nodeId.'_'.$itemId, 0);
		}
	}

	public function makeCacheForAll() {
		$items = EqItem::where('is_active','=',1)->get();
		$nodes = EqSupplyManagerNode::where('is_selectable', '=',1)->get();
		foreach ($nodes as $node) {
			// if(!Cache::has('is_cached_'.$node->id)){
				foreach ($items as $item) {
					$this->makeCache($item->id, $node->id);
				}
			// }
			Cache::forever('is_cached_'.$node->id,1);
		}
	}

	public function makeCacheForItem($itemId) {
		$nodes = EqSupplyManagerNode::where('is_selectable','=',1)->get();
		foreach ($nodes as $node) {
			$this->makeCache($itemId,$node->id);
		}
	}

	public function makeCacheForNode($nodeId) {
		$items = EqItem::where('is_active','=',1)->get();
		if(Cache::has('is_cached_'.$nodeId)){
			foreach ($items as $item) {
				$this->makeCache($item->id,$nodeId);
			}
		}
		Cache::forever('is_cached_'.$nodeId,1);
	}

	public function checkCacheForAll() {
		$items = EqItem::where('is_active','=',1)->get();
		$nodes = EqSupplyManagerNode::where('is_selectable', '=',1)->get();
		foreach ($nodes as $node) {
			if(!Cache::has('is_cached_'.$node->id)){
				echo $node->id.",";
				echo $node->full_name."<br>";
			}
		}
	}

	public function makeSubCacheClear($itemId) {
		$nodes = EqSupplyManagerNode::where('is_selectable','=',1)->get();
		foreach ($nodes as $node) {
			Cache::forget('is_sub_cached_'.$node->id.'_'.$itemId);
			Cache::forget('sub_wrecked_sum_'.$node->id.'_'.$itemId);
			Cache::forget('sub_avail_sum_'.$node->id.'_'.$itemId);
			Cache::forget('is_item_sub_cached_'.$itemId);
		}
	}

	public function makeSubCache($itemId) {
		$nodes = EqSupplyManagerNode::where('is_selectable','=',1)->get();
		foreach ($nodes as $node) {
			$this->makeCache($itemId,$node->id);
		}

		foreach ($nodes as $node){
			Cache::forget('is_sub_cached_'.$node->id.'_'.$itemId);
			Cache::forget('sub_wrecked_sum_'.$node->id.'_'.$itemId);
			Cache::forget('sub_avail_sum_'.$node->id.'_'.$itemId);
			Cache::forget('is_item_sub_cached_'.$itemId);

			$parentId = $node->id;
			// 자신의 파손, 가용수량을 가져온다.
			$wreckedSum = Cache::get('wrecked_sum_'.$parentId.'_'.$itemId);
			$availSum = Cache::get('avail_sum_'.$parentId.'_'.$itemId);


			// 자신부터 본청까지 올라가면서 parent에 자신의 파손, 가용수량을 더한다.
			while ($parentId != 0){
				if (!Cache::get('is_sub_cached_'.$parentId.'_'.$itemId)) {
					Cache::forever('sub_wrecked_sum_'.$parentId.'_'.$itemId, $wreckedSum);
					Cache::forever('sub_avail_sum_'.$parentId.'_'.$itemId, $availSum);
					Cache::forever('is_sub_cached_'.$parentId.'_'.$itemId, 1);
				} else {
					$subWreckedSum = Cache::get('sub_wrecked_sum_'.$parentId.'_'.$itemId);
					$subAvailSum = Cache::get('sub_avail_sum_'.$parentId.'_'.$itemId);
					Cache::forever('sub_wrecked_sum_'.$parentId.'_'.$itemId, $subWreckedSum + $wreckedSum);
					Cache::forever('sub_avail_sum_'.$parentId.'_'.$itemId, $subAvailSum + $availSum);
				}
				$parentId = EqSupplyManagerNode::find($parentId)->parent_manager_node;
			}
		}
		Cache::forever('is_item_sub_cached_'.$itemId, 1);
	}

	public function makeSubCacheForCode($codeId) {
		$items = EqItemCode::find($codeId)->items()->get();
		foreach ($items as $item) {
			$this->makeSubCache($item->id);
		}
	}

	public function makeSubCacheForAll() {
		$items = EqItem::where('is_active','=',1)->get();
		foreach ($items as $item) {
			if(!Cache::has('is_item_sub_cached_'.$item->id)){
				$this->makeSubCache($item->id);
			}
		}
	}

	public function checkSubCacheForAll() {
		$items = EqItem::where('is_active','=',1)->get();
		foreach ($items as $item) {
			if(!Cache::has('is_item_sub_cached_'.$item->id)){
				echo $item->id.": not Cached<br>";
			}
		}
	}

	public function clearItemData($nodeId, $itemId){
		$userNode = EqSupplyManagerNode::find($nodeId);
		$nodes = EqSupplyManagerNode::where('full_path','like',$userNode->full_path.'%')->get();

		DB::beginTransaction();
		foreach ($nodes as $node) {
			$invSet = EqInventorySet::where('item_id','=',$itemId)->where('node_id','=',$node->id)->first();
			$types = EqItem::find($itemId)->types;
			echo $node->full_name;
			if($invSet){
				foreach ($types as $t){
					$data = EqInventoryData::where('inventory_set_id','=',$invSet->id)->where('item_type_id','=',$t->id)->first();
					$data->count = 0;
					$data->wrecked = 0;
					echo "Data cleared <br>";
					if (!$data->save()) {
						return App::abort(500);
					}
				}
				Cache::forever('avail_sum_'.$node->id.'_'.$itemId, 0);
				Cache::forever('wrecked_sum_'.$node->id.'_'.$itemId, 0);
			}
		}
		DB::commit();
		echo "finished";
	}

	public function getNodeName($nodeId) {
		$node = EqSupplyManagerNode::find($nodeId);
		return $node->full_name;
	}

	public function showUpdatePersonnelForm(){
		$user = Sentry::getUser();
		$node = $user->supplySet->node;
		return View::make('equip.update-personnel-form', array('node'=>$node));
	}

	public function updatePersonnel() {
		$user = Sentry::getUser();
		$node = $user->supplySet->node;

		$node->personnel = (int) Input::get('personnel');
		$node->capacity = (int) Input::get('capacity');
		if (!$node->save()) {
			return array('msg'=>"관할부서 인원 변경에 실패했습니다.");
		}

		return array('msg'=>"관할부서 인원이 변경되었습니다.");
	}

	public function deleteConfirm($reqId) {

		$req = EqDeleteRequest::find($reqId);

		DB::beginTransaction();

		switch ($req->type) {
			case 'cap':
				$usage = EqCapsaicinUsage::find($req->usage_id);

				$event = $usage->event;

				// 타청에서 사용한걸 삭제할 경우 타청사용량에서 제거해줘야 함.
				if ($usage->cross) {
					$cross = $usage->cross;
					$io = $cross->io;
					if (!$io->delete()) {
						return '타청지원 추가량 삭제 중 오류가 발생했습니다';
					}
					if (!$cross->delete()) {
						return '타청지원내역 삭제 중 오류가 발생했습니다.';
					}
				}
				// 이제 사용내역 삭제함
				if (!$usage->delete()) {
					return '캡사이신 희석액 사용내역 삭제 중 오류가 발생했습니다';
				}

				if ($event->children->count() == 0) {
					if (!$event->delete()) {
						return '캡사이신 희석액 사용 행사 삭제 중 오류가 발생했습니다';
					}
				}

				break;
			case 'pava':
				$event = EqWaterPavaEvent::find($req->usage_id);
				if (!$event->delete()) {
					return App::abort(500);
				}
				break;
			default:
				return "wrong type.";
				break;
		}

		$req->confirmed = 1;
		if (!$req->save()) {
			return App::abort(500);
		}

		DB::commit();

		return "삭제되었습니다";
	}

	public function deleteDiscardedItem($nodeId, $itemId) {
		$node = EqSupplyManagerNode::find($nodeId);
		$children = EqSupplyManagerNode::where('full_path','like',$node->full_path.'%')->where('is_selectable','=',1)->get();
		DB::beginTransaction();
		foreach ($children as $child) {
			$set = EqItemDiscardSet::where('item_id','=',$itemId)->where('node_id','=',$child->id)->get();
			foreach ($set as $s) {
				$data = EqItemDiscardData::where('discard_set_id','=',$s->id)->get();
				foreach ($data as $d) {
					if(!$d->delete()){
						return App::abort(500);
					}
				}
				if(!$s->delete()){
					return App::abort(500);
				}
			}
		}
		DB::commit();
	}

	public function displayCheckPeriod() {
		$user = Sentry::getUser();
		$userNode = $user->supplySet->node;
		$regions = EqSupplyManagerNode::where('type_code','=',"D002")->get();
		$categories = EqCategory::orderBy('sort_order')->get();
		// 오늘 날짜
		$today = date('Y-m-d');
		foreach ($categories as $category) {
			foreach ($category->codes as $c) {
				$items[$c->id] = EqItem::where('item_code','=',$c->code)->where('is_active','=',1)->orderBy('acquired_date','DESC')->get();
				foreach ($items[$c->id] as $item) {
					$checkPeriod[$item->id] = EqQuantityCheckPeriod::where('item_id','=',$item->id)->where('node_id','=',$userNode->id)->first();
				}
			}
		}
		return View::make('equip.equips-term',get_defined_vars());
	}

	public function setCheckPeriodForItem() {
		$data=Input::all();
		$categories = EqCategory::all();
		$nodeId = (int)$data['node_id'];
		foreach ($categories as $category) {
			$codes = $category->codes;
			foreach ($codes as $code) {
				$items = $code->items;
				$categoryName = "category_".$category->id;
				$codeName = "code_".$code->id;
				foreach ($items as $item) {
					$checkPeriod = EqQuantityCheckPeriod::where('node_id','=',$nodeId)->where('item_id','=',$item->id)->first();
					$itemName = "item_".$item->id;
					$checkPeriod->check_end = $data[$itemName];
					if(!$checkPeriod->save()){
						return App::abort(500);
					}
				}
				if($data[$categoryName] != "") {
					foreach ($items as $item) {
						$checkPeriod = EqQuantityCheckPeriod::where('node_id','=',$nodeId)->where('item_id','=',$item->id)->first();
						$checkPeriod->check_end = $data[$categoryName];
						if(!$checkPeriod->save()){
							return App::abort(500);
						}
					}
				}
				if($data[$codeName] != "") {
					foreach ($items as $item) {
						$checkPeriod = EqQuantityCheckPeriod::where('node_id','=',$nodeId)->where('item_id','=',$item->id)->first();
						$checkPeriod->check_end = $data[$codeName];
						if(!$checkPeriod->save()){
							return App::abort(500);
						}
					}
				}
			}
		}
		return "저장되었습니다.";
	}

	public function getCheckPeriod() {
		$regionId = Input::get('regionId');
		$region = EqSupplyManagerNode::find($regionId)->node_name;
		$checkPeriodData = array();
		$data = array();
		$checkPeriods = EqQuantityCheckPeriod::where('node_id','=',$regionId)->get();
		foreach ($checkPeriods as $c) {
			$checkPeriodData[$c->item_id] = $c->check_end;
		}
		$data[] = $checkPeriodData;
		$data[] = $region;
		$data[] = $regionId;

		return $data;
	}

	// Add node ids, item_ids for each node on eq_quantity_check_period table
	public function addNodes() {
		$nodes = EqSupplyManagerNode::where('type_code','=','D002')->get();
		$items = EqItem::where('is_active','=',1)->get();
		DB::beginTransaction();
		foreach ($nodes as $node) {
			foreach ($items as $item) {
				$checkPeriod = new EqQuantityCheckPeriod;
				$checkPeriod->check_start = "2016-01-01";
				$checkPeriod->check_end = "2016-01-30";
				$checkPeriod->item_id = $item->id;
				$checkPeriod->node_id = $node->id;
				if (!$checkPeriod->save()) {
					return App::abort(500);
				}
			}
		}
		DB::commit();
		return "finished";
	}

	//경기북부청 신설로 checkPeriod 만들어 주기
	public function addNode($nodeId) {
		$items = EqItem::where('is_active','=',1)->get();
		DB::beginTransaction();
		foreach ($items as $item) {
			$checkPeriod = new EqQuantityCheckPeriod;
			$checkPeriod->check_start = "2016-01-01";
			$checkPeriod->check_end = "2016-01-30";
			$checkPeriod->item_id = $item->id;
			$checkPeriod->node_id = $nodeId;
			if (!$checkPeriod->save()) {
				return App::abort(500);
			}
		}
		DB::commit();
		return "finished";
	}

	//EqSupplyManagerSet 추가로 인해 데이터 넣어주기
	public function insertNodeAndManager() {
		$nodes = EqSupplyManagerNode::all();
		DB::beginTransaction();
		foreach ($nodes as $node) {
			$set = new EqSupplyManagerSet;
			$set->node_id = $node->id;
			$set->manager_id = $node->manager_id;
			if (!$set->save()) {
				return App::abort(500);
			}
		}
		DB::commit();
		return "finished";
	}
}