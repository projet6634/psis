<div class="panel panel-default">
	<div class="panel-heading">
		<h3 class="panel-title"><strong>{{ $node->node_name }} 캡사이신 희석액 보유 현황</strong></h3>
	</div>
	<div class="panel-body">
		<div class="row">
			<div class="col-xs-12">
				<table class="table table-condensed table-hover table-striped table-bordered" id="capsaicin_table">
				<thead>
					<tr>
						<th rowspan="3">구분</th>
						<th colspan="2">캡사이신 보유량</th>
						<th colspan="3">사용량</th>
						<th colspan="3">사용횟수</th>
						<th rowspan="2">추가량</th>
						<th rowspan="3" colspan="3">비고</th>
					</tr>
					<tr>
						<th>현재보유량</th>
						<th>최초보유량</th>
						<th>계</th>
						<th>훈련시</th>
						<th>집회 시위시</th>
						<th>계</th>
						<th>훈련시</th>
						<th>집회 시위시</th>
					</tr>
					<tr>
						<th>1</th>
						<th></th>
						<th></th>
						<th></th>
						<th></th>
						<th></th>
						<th></th>
						<th></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					@for ($i=1; $i <=12 ; $i++)
					<tr>
						<th style="text-align: center;">{{$i}}월</th>
						<td colspan="2"></td>
						<td></td>
						<td></td>
						<td></td>
						<td></td>
						<td></td>
						<td></td>
						<td></td>
						<td></td>
					</tr>
					@endfor
				</tbody>
				</table>
			</div>
		</div>

	</div>
</div>