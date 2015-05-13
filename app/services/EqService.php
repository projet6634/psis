<?php 

use Carbon\Carbon;

class EqService extends BaseService {

	public function deleteSupplySet($id) {
		$s = EqItemSupplySet::find($id);
		if (!$s) {
			return 'foo';
		}
		$datas = $s->children;

		$item = $s->item;
		
		DB::beginTransaction();

		// 보급을 삭제하면서 각 하위관서에 보급했던 수량을 다시 가져온다.

		// 1. 보급한 관서의 인벤토리 수량 더하기
		$supplierNodeId = $s->from_node_id;

		$supplierInvSet = EqInventorySet::where('node_id','=',$supplierNodeId)->where('item_id','=',$item->id)->first();

		foreach ($item->types as $t) {
			$suppliedCount = EqItemSupply::where('supply_set_id','=',$s->id)->where('item_type_id','=',$t->id)->sum('count');
			$invData = EqInventoryData::where('inventory_set_id','=',$supplierInvSet->id)->where('item_type_id','=',$t->id)->first();
			$invData->count += $suppliedCount;
			if (!$invData->save()) {
				return App::abort(500);
			}
		} 

		// 2. 보급받은 관서의 인벤토리 수량 빼기
		foreach ($datas as $d) {
			$itemTypeId = $d->item_type_id;
			$toNodeId = $d->to_node_id;
			$invSet = EqInventorySet::where('node_id','=',$toNodeId)->where('item_id','=',$s->item_id)->first();
			$invData = EqInventoryData::where('inventory_set_id','=',$invSet->id)->where('item_type_id','=',$d->item_type_id)->first();
			
			$invData->count -= $d->count;
			if (!$invData->save()) {
				return App::abort(500);
			}

			if (!$d->delete()) {
				return App::abort(500);
			}
		}

		if (!$s->delete()) {
			return App::abort(500);
		}

		// 3. 하위 부서의 보급도 취소하기
		$supplierNode = EqSupplyManagerNode::find($supplierNodeId);
		$lowerNodes = $supplierNode->managedChildren;

		if (!$lowerNodes) {
			return;
		}

		// 하위 노드에서 보급한 내역을 찾아 지운다.
		foreach ($lowerNodes as $n) {
			$supSets = EqItemSupplySet::where('item_id','=',$item->id)->where('from_node_id','=',$n->id)->where('created_at','>',$s->created_at)->get();
			foreach ($supSets as $s) {
				$this->deleteSupplySet($s->id);
			}
		}

		DB::commit();

		return 1;
	}

	public function getScopeDept(User $user) {
		if (!$user->isSuperUser() && $user->department->type_code != Department::TYPE_HEAD) {
			// 사용자의 관서 종류에 따라 조회 범위 설정
			if ($user->department->type_code == Department::TYPE_REGION) {
				$scopeRootDept = $user->department->region();
			} else {
				$scopeRootDept = $user->department;
			}
			return $scopeRootDept;
		} else {
			return null;
		}
	}

	public function getEventType($code) {
		switch ($code) {
			case 'assembly':
				$eventType = '집회';
				break;
			case 'training':
				$eventType = '훈련';
				break;
			default:
				return App::abort(500);
				break;
		}
		return $eventType;
	}

	/**
	 * 사용자에게 허용된 도메인의 카테고리들을 가져온다
	 * @param User $user 
	 * @return Collection<EqCategory>
	 */
	public function getVisibleCategoriesQuery(User $user) {

		$query = EqCategory::with('domain')->orderBy('domain_id', 'asc')
						->orderBy('name', 'asc');

		$visibleDomainIds = $this->getVisibleDomains($user)->fetch('id')->toArray();
		if (count($visibleDomainIds) == 0) {
			$visibleDomainIds[] = -1;
		}

		$query->whereIn('domain_id', $visibleDomainIds);
		return $query;
	}	

	public function getVisibleDomains(User $user) {
		return EqDomain::all()->filter(function($domain) use ($user) {
			return $user->hasAccess($domain->permission);
		});
	}

	public function getVisibleItemsQuery(User $user) {

		$visibleCategoryIds = $this->getVisibleCategoriesQuery($user)->lists('id');

		if (count($visibleCategoryIds) == 0) {
			$visibleCategoryIds[] = -1;
		}

		$query = EqItem::whereIn('category_id', $visibleCategoryIds)
						->orderBy('category_id', 'asc')
						->orderBy('name', 'asc');
		return $query;
	}

	public function getInventoriesQuery(User $user) {
		$query = EqInventory::query();

		$scope = $this->getScopeDept($user);

		if ($scope) {
			$query->where('full_path', 'like', $scope->full_path.'%');
		}

		return $query;
	}

	public function exportCapsaicinByEvent($rows, $node, $now) {
		//xls obj 생성
		$objPHPExcel = new PHPExcel();
		if (isset($node)) {
			$fileName = $node->node_name.' 사용내역'; 
		} else {
			$fileName = '캡사이신 희석액 사용내역'; 
		}
		//obj 속성
		$objPHPExcel->getProperties()
			->setTitle($fileName)
			->setSubject($fileName);
		//셀 정렬(가운데)
		$objPHPExcel->getDefaultStyle()->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER)->setVertical(PHPExcel_Style_Alignment::VERTICAL_CENTER);
		
		$sheet = $objPHPExcel->setActiveSheetIndex(0);
		
		$sheet->setCellValue('a1','일자');
		$sheet->setCellValue('b1','관서명');
		$sheet->setCellValue('c1','중대');
		$sheet->setCellValue('d1','행사유형');
		$sheet->setCellValue('e1','사용장소');
		$sheet->setCellValue('f1','행사명');
		$sheet->setCellValue('g1','사용량(ℓ)');
		//양식 부분 끝
		//이제 사용내역 나옴
		for ($i=1; $i <= sizeof($rows); $i++) { 
			$sheet->setCellValue('a'.($i+1),$rows[$i-1]->date);
			$sheet->setCellValue('b'.($i+1),$rows[$i-1]->node->node_name);
			$sheet->setCellValue('c'.($i+1),$rows[$i-1]->user_node->node_name);
			$sheet->setCellValue('d'.($i+1),$rows[$i-1]->type);
			$sheet->setCellValue('e'.($i+1),$rows[$i-1]->location);
			$sheet->setCellValue('f'.($i+1),$rows[$i-1]->event_name);
			$sheet->setCellValue('g'.($i+1),round($rows[$i-1]->amount, 2));
		}
		

		//파일로 저장하기
		$writer = PHPExcel_IOFactory::createWriter($objPHPExcel,'Excel2007');
		header("Pragma: public");
		header("Expires: 0");
		header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
		header("Content-Type: application/force-download");
		header('Content-type: application/vnd.ms-excel');
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Encoding: UTF-8');
		header('Content-Disposition: attachment; filename="'.$fileName.' '.$now.'.xlsx"');
		header("Content-Transfer-Encoding: binary ");
		$writer->save('php://output');
		return;
	}

	public function exportWaterByEvent($rows, $node, $now) {
		//xls obj 생성
		$objPHPExcel = new PHPExcel();
		if (isset($node)) {
			$fileName = $node->node_name.' 물 사용내역'; 
		} else {
			$fileName = '물 사용내역'; 
		}
		//obj 속성
		$objPHPExcel->getProperties()
			->setTitle($fileName)
			->setSubject($fileName);
		//셀 정렬(가운데)
		$objPHPExcel->getDefaultStyle()->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER)->setVertical(PHPExcel_Style_Alignment::VERTICAL_CENTER);
		
		$sheet = $objPHPExcel->setActiveSheetIndex(0);
		
		$sheet->setCellValue('a1','일자');
		$sheet->setCellValue('b1','관서명');
		$sheet->setCellValue('c1','사용장소');
		$sheet->setCellValue('d1','행사명');
		$sheet->setCellValue('e1','사용량(ton)');
		//양식 부분 끝
		//이제 사용내역 나옴
		for ($i=1; $i <= sizeof($rows); $i++) { 
			$sheet->setCellValue('a'.($i+1),$rows[$i-1]->date);
			$sheet->setCellValue('b'.($i+1),$rows[$i-1]->node->node_name);
			$sheet->setCellValue('c'.($i+1),$rows[$i-1]->location);
			$sheet->setCellValue('d'.($i+1),$rows[$i-1]->event_name);
			$sheet->setCellValue('e'.($i+1),round(($i+1),$rows[$i-1]->amount, 2));
		}
		

		//파일로 저장하기
		$writer = PHPExcel_IOFactory::createWriter($objPHPExcel,'Excel2007');
		header("Pragma: public");
		header("Expires: 0");
		header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
		header("Content-Type: application/force-download");
		header('Content-type: application/vnd.ms-excel');
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Encoding: UTF-8');
		header('Content-Disposition: attachment; filename="'.$fileName.' '.$now.'.xlsx"');
		header("Content-Transfer-Encoding: binary ");
		$writer->save('php://output');
		return;
	}

	public function exportCapsaicinByMonth($data, $node, $now, $year){
		//xls obj 생성
		$objPHPExcel = new PHPExcel();
		if (isset($node)) {
			$fileName = $node->full_name.' '.$year.' 현황'; 
		} else {
			$fileName = $year.' 현황'; 
		}
		//obj 속성
		$objPHPExcel->getProperties()
			->setTitle($fileName)
			->setSubject($fileName);
		//셀 정렬(가운데)
		$objPHPExcel->getDefaultStyle()->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER)->setVertical(PHPExcel_Style_Alignment::VERTICAL_CENTER);
		
		$sheet = $objPHPExcel->setActiveSheetIndex(0);
		$sheet->mergeCells('a1:a3');
		$sheet->mergeCells('b1:c1');
		$sheet->mergeCells('d1:f1');
		$sheet->mergeCells('g1:i1');
		$sheet->mergeCells('j1:j2');
		$sheet->mergeCells('k1:k2');

		for ($i=1; $i <=12 ; $i++) { 
			$sheet->mergeCells('b'.($i+3).':c'.($i+3));
		}

		$sheet->setCellValue('a1','구분');
		$sheet->setCellValue('b1','보유량(ℓ)');
		$sheet->setCellValue('d1','사용량(ℓ)');
		$sheet->setCellValue('g1','사용횟수');
		$sheet->setCellValue('j1','추가량(ℓ)');
		$sheet->setCellValue('k1','불용량(ℓ)');
		$sheet->setCellValue('b2','현재보유량(ℓ)');
		$sheet->setCellValue('c2','최초보유량(ℓ)');
		$sheet->setCellValue('d2','계');
		$sheet->setCellValue('e2','훈련시');
		$sheet->setCellValue('f2','집회 시위시');
		$sheet->setCellValue('g2','계');
		$sheet->setCellValue('h2','훈련시');
		$sheet->setCellValue('i2','집회 시위시');
		$sheet->setCellValue('b3',isset($data['presentStock']) ? round($data['presentStock'], 2) : '');
		$sheet->setCellValue('c3',round($data['firstDayHolding'], 2));
		$sheet->setCellValue('d3',round($data['usageSumSum'], 2));
		$sheet->setCellValue('e3',round($data['usageTSum'], 2));
		$sheet->setCellValue('f3',round($data['usageASum'], 2));
		$sheet->setCellValue('g3',$data['timesSumSum']);
		$sheet->setCellValue('h3',$data['timesTSum']);
		$sheet->setCellValue('i3',$data['timesASum']);
		$sheet->setCellValue('j3',round($data['additionSum'], 2));
		$sheet->setCellValue('k3',round($data['discardSum'], 2));
		//양식 부분 끝
		//이제 월별 자료 나옴
		
		for ($i=1; $i <=12 ; $i++) { 
			$sheet->setCellValue('A'.($i+3), $i.'월');
			if (isset($data['stock'][$i])) {
				$sheet->setCellValue('B'.($i+3), round($data['stock'][$i], 2) );
				$sheet->setCellValue('D'.($i+3), round($data['usageSum'][$i], 2) );
				$sheet->setCellValue('E'.($i+3), round($data['usageT'][$i], 2) );
				$sheet->setCellValue('F'.($i+3), round($data['usageA'][$i], 2) );
			}
			
			$sheet->setCellValue('G'.($i+3), $data['timesSum'][$i] );
			$sheet->setCellValue('H'.($i+3), $data['timesT'][$i] );
			$sheet->setCellValue('I'.($i+3), $data['timesA'][$i] );
			$sheet->setCellValue('J'.($i+3), round($data['addition'][$i], 2) );
			$sheet->setCellValue('K'.($i+3), round($data['discard'][$i], 2) );

		}

		//파일로 저장하기
		$writer = PHPExcel_IOFactory::createWriter($objPHPExcel,'Excel2007');
		header("Pragma: public");
		header("Expires: 0");
		header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
		header("Content-Type: application/force-download");
		header('Content-type: application/vnd.ms-excel');
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Encoding: UTF-8');
		header('Content-Disposition: attachment; filename="'.$fileName.' '.$now.'.xlsx"');
		header("Content-Transfer-Encoding: binary ");
		$writer->save('php://output');
	}

	public function exportGeneralTable($node) {

		$now = Carbon::now();
		$itemTotalNum = 0;

		$categories = EqCategory::where('domain_id','=',1)->get();

		$objPHPExcel = new PHPExcel();
		$fileName = '집회시위 관리장비 점검 총괄표('.$node->full_name.')'; 
		
		//obj 속성
		$objPHPExcel->getProperties()
			->setTitle($fileName)
			->setSubject($fileName);
		//셀 정렬(가운데)
		$objPHPExcel->getDefaultStyle()->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER)->setVertical(PHPExcel_Style_Alignment::VERTICAL_CENTER);
		
		$sheet = $objPHPExcel->setActiveSheetIndex(0);

		//양식 만들기
		$sheet->mergeCells('a1:a2');
		$sheet->setCellValue('a1', '기관명');
		$sheet->mergeCells('b1:d2');
		$sheet->setCellValue('b1', '장비명');

		//총계 열 추가
		$lastColIdx = PHPExcel_Cell::columnIndexFromString($sheet->getHighestDataColumn());

		$sheet->mergeCells(PHPExcel_Cell::stringFromColumnIndex($lastColIdx).'1:'.PHPExcel_Cell::stringFromColumnIndex($lastColIdx+2).'1');
		$sheet->setCellValue(PHPExcel_Cell::stringFromColumnIndex($lastColIdx).'1', '총계');
		$sheet->setCellValue(PHPExcel_Cell::stringFromColumnIndex($lastColIdx).'2','보급');
		$sheet->setCellValue(PHPExcel_Cell::stringFromColumnIndex($lastColIdx+1).'2','파손');
		$sheet->setCellValue(PHPExcel_Cell::stringFromColumnIndex($lastColIdx+2).'2','사용가능');

		//4년이상 초과 열
		$lastColIdx = PHPExcel_Cell::columnIndexFromString($sheet->getHighestDataColumn());

		$sheet->mergeCells(PHPExcel_Cell::stringFromColumnIndex($lastColIdx).'1:'.PHPExcel_Cell::stringFromColumnIndex($lastColIdx+2).'1');
		$sheet->setCellValue(PHPExcel_Cell::stringFromColumnIndex($lastColIdx).'1', $now->subYears(4)->year.'년 이전');
		$sheet->setCellValue(PHPExcel_Cell::stringFromColumnIndex($lastColIdx).'2','보급');
		$sheet->setCellValue(PHPExcel_Cell::stringFromColumnIndex($lastColIdx+1).'2','파손');
		$sheet->setCellValue(PHPExcel_Cell::stringFromColumnIndex($lastColIdx+2).'2','사용가능');

		//4개년 열 추가
		for ($i=0; $i <= 3; $i++) { 
			$lastColIdx = PHPExcel_Cell::columnIndexFromString($sheet->getHighestDataColumn());

			$sheet->mergeCells(PHPExcel_Cell::stringFromColumnIndex($lastColIdx).'1:'.PHPExcel_Cell::stringFromColumnIndex($lastColIdx+2).'1');
			$sheet->setCellValue(PHPExcel_Cell::stringFromColumnIndex($lastColIdx).'1', $now->addYear()->year.'년');

			$sheet->setCellValue(PHPExcel_Cell::stringFromColumnIndex($lastColIdx).'2','보급');
			$sheet->setCellValue(PHPExcel_Cell::stringFromColumnIndex($lastColIdx+1).'2','파손');
			$sheet->setCellValue(PHPExcel_Cell::stringFromColumnIndex($lastColIdx+2).'2','사용가능');
		}
		
		$threeYearsAgo = Carbon::now()->subYears(3)->firstOfYear();

		//장비별 행, 행별 자료 입력
		foreach ($categories as $c) {

			$itemsInCategory = EqItemCode::where('category_id','=',$c->id)->get();
			$itemTotalNum += sizeof($itemsInCategory);
			$lastRow = $sheet->getHighestRow();
			$sheet->setCellValue('b'.($lastRow+1), $c->name);
			$sheet->mergeCells('b'.($lastRow+1).':b'.($lastRow+sizeof($itemsInCategory)));

			
			for ($i=1; $i<=sizeof($itemsInCategory) ; $i++) { 
				$sheet->setCellValue('c'.($lastRow+$i), $itemsInCategory[$i-1]->code);
				$sheet->setCellValue('d'.($lastRow+$i), $itemsInCategory[$i-1]->title);

				//TODO
				//총괄표 양식에 자료 넣기
				
				$itemCode = $itemsInCategory[$i-1];
				$items = $itemCode->items;

				//supply의 target이 node인것들 합
				$suppliedSum = EqItemSupply::where('to_node_id','=',$node->id)->whereHas('supplySet', function($q) use ($itemCode){
					$q->whereHas('item', function($q) use ($itemCode){
						$q->whereHas('code', function($q) use ($itemCode){
							$q->where('item_code','=',$itemCode->code);
						});
					});
				})->sum('count');

				$wreckedSum = EqInventoryData::whereHas('parentSet', function($q) use($node, $itemCode) {
					$q->where('node_id','=',$node->id)->whereHas('item', function($q) use ($itemCode) {
						$q->whereHas('code', function($q) use ($itemCode){
							$q->where('item_code','=',$itemCode->code);
						});
					});
				})->sum('wrecked');

				$holdingSum = EqInventoryData::whereHas('parentSet', function($q) use($node, $itemCode) {
					$q->where('node_id','=',$node->id)->whereHas('item', function($q) use ($itemCode) {
						$q->whereHas('code', function($q) use ($itemCode){
							$q->where('item_code','=',$itemCode->code);
						});
					});
				})->sum('count') - $wreckedSum;

				$sheet->setCellValue('e'.($lastRow+$i), $suppliedSum);
				//inventory에서 해당 물품의 wrecked sum
				$sheet->setCellValue('f'.($lastRow+$i), $wreckedSum);
				//inventory에서 count - wrecked의 sum
				$sheet->setCellValue('g'.($lastRow+$i), $holdingSum);
				
				$suppliedSumBefore4years = EqItemSupply::where('to_node_id','=',$node->id)->whereHas('supplySet', function($q) use ($itemCode, $threeYearsAgo){
					$q->whereHas('item', function($q) use ($itemCode, $threeYearsAgo){
						$q->where('supplied_date','<',$threeYearsAgo)->whereHas('code', function($q) use ($itemCode){
							$q->where('item_code','=',$itemCode->code);
						});
					});
				})->sum('count');

				$wreckedSumBefore4years = EqInventoryData::whereHas('parentSet', function($q) use($node, $itemCode, $threeYearsAgo) {
					$q->where('node_id','=',$node->id)->whereHas('item', function($q) use ($itemCode, $threeYearsAgo) {
						$q->where('acquired_date','<',$threeYearsAgo)->whereHas('code', function($q) use ($itemCode){
							$q->where('item_code','=',$itemCode->code);
						});
					});
				})->sum('wrecked');

				$availSumBefore4years = EqInventoryData::whereHas('parentSet', function($q) use($node, $itemCode, $threeYearsAgo) {
					$q->where('node_id','=',$node->id)->whereHas('item', function($q) use ($itemCode, $threeYearsAgo) {
						$q->where('acquired_date','<',$threeYearsAgo)->whereHas('code', function($q) use ($itemCode){
							$q->where('item_code','=',$itemCode->code);
						});
					});
				})->sum('count') - $wreckedSumBefore4years;

				//supply의 target이 node인 것 중 supplied date가 4년 이전인것
				$sheet->setCellValue('h'.($lastRow+$i), $suppliedSumBefore4years);
				$sheet->setCellValue('i'.($lastRow+$i), $wreckedSumBefore4years);
				$sheet->setCellValue('j'.($lastRow+$i), $availSumBefore4years);

				for ($j=0; $j <=3 ; $j++) { 
					$ColIdx = 10+3*$j;
					// TODO
					// 연도별 수량 입력할 곳
					$year = $threeYearsAgo->year + $j;
					$lastDayOfLastYear = Carbon::parse('last day of December '.($year-1));
					$firstDayOfNextYear = Carbon::parse('first day of January '.($year+1));

					$suppliedSumInYear = EqItemSupply::where('to_node_id','=',$node->id)->whereHas('supplySet', function($q) use ($itemCode, $lastDayOfLastYear, $firstDayOfNextYear){
						$q->whereHas('item', function($q) use ($itemCode, $lastDayOfLastYear, $firstDayOfNextYear){
							$q->where('supplied_date','>', $lastDayOfLastYear)->where('supplied_date','<', $firstDayOfNextYear)->whereHas('code', function($q) use ($itemCode){
								$q->where('item_code','=',$itemCode->code);
							});
						});
					})->sum('count');

					$wreckedSumInYear = EqInventoryData::whereHas('parentSet', function($q) use($node, $itemCode, $lastDayOfLastYear, $firstDayOfNextYear) {
						$q->where('node_id','=',$node->id)->whereHas('item', function($q) use ($itemCode, $lastDayOfLastYear, $firstDayOfNextYear) {
							$q->where('acquired_date','>', $lastDayOfLastYear)->where('acquired_date','<', $firstDayOfNextYear)->whereHas('code', function($q) use ($itemCode){
								$q->where('item_code','=',$itemCode->code);
							});
						});
					})->sum('wrecked');

					$availSumInYear = EqInventoryData::whereHas('parentSet', function($q) use($node, $itemCode, $lastDayOfLastYear, $firstDayOfNextYear) {
						$q->where('node_id','=',$node->id)->whereHas('item', function($q) use ($itemCode, $lastDayOfLastYear, $firstDayOfNextYear) {
							$q->where('acquired_date','>', $lastDayOfLastYear)->where('acquired_date','<', $firstDayOfNextYear)->whereHas('code', function($q) use ($itemCode){
								$q->where('item_code','=',$itemCode->code);
							});
						});
					})->sum('count') - $wreckedSumInYear;

					$sheet->setCellValue(PHPExcel_Cell::stringFromColumnIndex($ColIdx).($lastRow+$i), $suppliedSumInYear);
					$sheet->setCellValue(PHPExcel_Cell::stringFromColumnIndex($ColIdx+1).($lastRow+$i), $wreckedSumInYear);
					$sheet->setCellValue(PHPExcel_Cell::stringFromColumnIndex($ColIdx+2).($lastRow+$i), $availSumInYear);
				}
			}
		}
		$sheet->setCellValue('a3', $node->node_name);
		$sheet->mergeCells('a3:a'.($itemTotalNum+2));

		//파일로 저장하기
		$writer = PHPExcel_IOFactory::createWriter($objPHPExcel,'Excel2007');
		header("Pragma: public");
		header("Expires: 0");
		header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
		header("Content-Type: application/force-download");
		header('Content-type: application/vnd.ms-excel');
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Encoding: UTF-8');
		header('Content-Disposition: attachment; filename="'.$fileName.' '.$now.'.xlsx"');
		header("Content-Transfer-Encoding: binary ");
		$writer->save('php://output');
	}
}