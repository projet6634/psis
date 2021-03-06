<?php

class BgMobService {
	
	public function getSitStatQuery($startMonth, $endMonth, $deptId, $groupByRegion)
	{
		$startDate = date('Y-m-01', strtotime($startMonth.'-01'));
		$endDate = date('Y-m-t', strtotime($endMonth.'-01'));

		$query = DB::table('bg_mob')
					->leftJoin('departments','departments.id','=','bg_mob.dept_id');

		$mobCodes = Code::withCategory('B002');

		$selects = array(
				DB::raw('DATE_FORMAT(mob_date, "%Y-%m") AS belong_month')
			);

		if ($groupByRegion)
		{
			$selects[] = 
				DB::raw('LEFT(departments.full_name, LOCATE(" ", departments.full_name, 2)) AS dept_name');
		}
		else
		{
			$selects[] = DB::raw('TRIM(REPLACE(departments.full_name, ":", " ")) AS dept_name');
		}

		foreach ($mobCodes as $key=>$code)
		{
			if (!$groupByRegion) 
			{
				$sql = 'SELECT COUNT(*) FROM bg_mob AS mob'.$key.'
					WHERE mob'.$key.'.mob_code = "'.$code->code.'" AND 
						mob'.$key.'.mob_date BETWEEN DATE_FORMAT(bg_mob.mob_date, "%Y-%m-01") AND LAST_DAY(bg_mob.mob_date) AND
						mob'.$key.'.dept_id = bg_mob.dept_id';
			}
			else
			{
				$sql = 'SELECT COUNT(*) FROM bg_mob AS mob'.$key.'
					LEFT JOIN departments AS d ON d.id = mob'.$key.'.dept_id
					WHERE mob'.$key.'.mob_code = "'.$code->code.'" AND 
						mob'.$key.'.mob_date BETWEEN DATE_FORMAT(bg_mob.mob_date, "%Y-%m-01") AND LAST_DAY(bg_mob.mob_date) AND
						d.full_path LIKE CONCAT("%",LEFT(departments.full_path, LOCATE(":",departments.full_path,2)),"%")';
			}

			$selects[] = DB::raw('('.$sql.') AS c'.$key);
		}

		$selects[] = DB::raw('SUM(amount) AS amount');
		$query->select($selects)
				->where('mob_date', '>=', $startDate)
				->where('mob_date', '<=', $endDate)
				->groupBy(DB::raw('DATE_FORMAT(mob_date, "%Y-%m")'));

		$user = Sentry::getUser();
		if (!$user->hasAccess('budget.admin'))
		{
			$userDeptId = $user->dept_id;
			$query->where('departments.full_path', 'like', "%$userDeptId%");
		}
		
		if ($deptId)
		{
			$query->where('departments.full_path', 'like', "%:$deptId:%");
		}

		if ($groupByRegion)
		{
			// $query->groupBy('dept_id');
			$query->groupBy(DB::raw('LEFT(full_path, LOCATE(":",full_path,2))'));
		}
		else
		{
			$query->groupBy('dept_id');
		}

		$query->orderBy('departments.sort_order', 'asc');

		return $query;
	}

	public function getPayrollQuery($start, $end, $deptId, $mobCode, $actual)
	{
		$query = DB::table('bg_mob')
					->leftJoin('departments','departments.id','=','bg_mob.dept_id')
					->leftJoin('codes AS rank', function($query){
						$query->on('rank.code','=','bg_mob.rank_code')
							->where('rank.category_code', '=', 'H001');
					})
					->leftJoin('codes AS mobSit', function($query){
						$query->on('mobSit.code','=','bg_mob.mob_code')
							->where('mobSit.category_code', '=', 'B002');
					});

		$query->select(array(
			'bg_mob.id',
			DB::raw('TRIM(REPLACE(departments.full_name, ":", " ")) AS dept_name'),
			'rank.title AS rankTitle',
			'receiver_name',
			'mob_date',
			'mobSit.title as sitTitle',
			'mob_summary',
			DB::raw('DATE_FORMAT(start_time, "%H:%i") as start'),
			DB::raw('DATE_FORMAT(end_time, "%H:%i") as end'),
			'amount',
			DB::raw('IF(actual, "O", "") AS actual')
		));

		$query->where('mob_date', '>=', $start)->where('mob_date','<=',$end);

		$user = Sentry::getUser();
		if (!$user->hasAccess('budget.admin'))
		{
			$userDeptId = $user->dept_id;
			$query->where('departments.full_path', 'like', "%$userDeptId%");
		}

		if ($deptId)
		{
			$query->where('departments.full_path', 'like', '%:'.$deptId.':%');
		}

		if ($actual)
		{
			$query->where('actual', '=', 1);
		}

		if ($mobCode)
		{
			$query->where('mob_code','=',$mobCode);
		}

		return $query;
	}

	public function insertPayroll($data)
	{
		$costs = DB::table('bg_mob_cost')->get();
		$editableStart = strtotime($this->getEditableDateStart());

		$user = Sentry::getUser();

		foreach ($data as $key=>$row)
		{
			$startTS = strtotime($row['start_time']);
			$endTS = strtotime($row['end_time']);

			if ($startTS >= $endTS)
			{
				return -1;
			}

			if (!$user->hasAccess('budget.admin') && 
				strtotime($row['mob_date']) < $editableStart) 
			{
				return -2;
			}

			if (!$user->hasAccess('budget.admin') &&
				!Department::isAncestor($row['dept_id'], $user->dept_id))
			{
				return -3;
			}

			$data[$key]['amount'] = $this->calculateAmount($costs, $startTS, $endTS);
		}

		DB::table('bg_mob')->insert($data);
		return 0;
	}

	private function calculateAmount($costs, $startTS, $endTS)
	{
		$hourDiff = ($endTS - $startTS) / 60 / 60;

		foreach ($costs as $cost)
		{
			if ($cost->start <= $hourDiff && $hourDiff < $cost->end)
			{
				return $cost->cost;
			}
		}
		return 0;
	}

	public function getEditableDateStart()
	{
		$belongMonth = date('Y-m-01', strtotime('-1 month'));
		$closeDate = $this->getCloseDate($belongMonth);

		if (time() < strtotime($closeDate))
		{
			$closeDate = $this->getCloseDate(date('Y-m-01', strtotime('-1 month', strtotime($belongMonth))));
		}

		return date('Y-m-d', strtotime('+1 day', strtotime($closeDate)));	
	}

	public function getCloseDate($belongMonth)
	{
		$result = DB::table('bg_mob_close_date')->where('belong_month', '=', $belongMonth)->first();
		if (!$result)
		{
			$configs = PSConfig::category('budget.mob');
			$dateNum = isset($configs['budget.mob.close_date']) ? $configs['budget.mob.close_date'] : '-1';
			$time = isset($configs['budget.mob.close_time']) ? $configs['budget.mob.close_time'] : '00:00';

			if ($dateNum == -1)
			{
				$closeDate = date('Y-m-t '.$time, strtotime('+1 month', strtotime($belongMonth)));
			}
			else
			{
				$closeDate = date('Y-m-'.$dateNum.' '.$time, strtotime('+1 month', strtotime($belongMonth)));
			}
			
			DB::table('bg_mob_close_date')->insert(array(
						'belong_month'=>$belongMonth,
						'close_date'=>$closeDate
					));
		}
		else
		{
			$closeDate = $result->close_date;
		}

		return $closeDate;
	}

	public function delete($ids) 
	{
		$editableStart = $this->getEditableDateStart();
		
		$forbiddens = DB::table('bg_mob')->whereIn('id', $ids)->where('mob_date', '<', $editableStart)->get();

		if (!Sentry::getUser()->hasAccess('budget.admin') && count($forbiddens) > 0) 
		{
			return -1;
		}

		DB::table('bg_mob')->whereIn('id', $ids)->delete();
		return 0;
	}
}