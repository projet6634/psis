@extends('layouts.master')

@section('content')
	
	<div class="row">
		<div class="col-xs-4">
			<img src="{{ url('/static/img/eq_main.gif') }}" alt="" class="col-xs-12" style="padding:0px; border: 1px solid transparent; box-shadow: 0 1px 1px rgba(0,0,0,0.05);" />
		</div>
		<div class="col-xs-8">
			<div class="row">
				<div class="col-xs-12">
					{{ View::make('widget.lastest', array('board'=>'notice_equip', 'title'=>'공지사항-장비관리')) }}
				</div>
			</div>
			<div class="row">
				<div class="col-xs-12">
					<div class="panel panel-default">
						<div class="panel-heading">
							<h3 class="panel-title pull-left">
								<strong>관리전환 최근내역</strong>
							</h3>
							<a href="{{ url('equips/convert') }}" class="label label-primary pull-right"> @lang('lastest.more') </a>
							<div class="clearfix"></div>
						</div>
						<div class="panel-body">
							<span class="label label-success">입고내역</span>
							<table class="table table-condensed table-striped table-hover">
								<thead>
									<tr>
										<th>장비명</th>
										<th>출처</th>
										<th>날짜</th>
										<th>확인여부</th>
									</tr>
								</thead>
								<tbody>
									@foreach ($inbounds as $i)
									<tr>
										<td>{{ $i->item->code->title }}</td>
										<td>{{ $i->fromNode->node_name }}</td>
										<td>{{ $i->converted_date }}</td>
										@if ($i->is_confirmed == 1)
										<td>
											<span class="label label-success"><span class="glyphicon glyphicon-ok"></span> {{ $i->confirmed_date }}</span>
										</td>
										@else
										<td>
											<span class="label label-danger"><span class="glyphicon glyphicon-question-sign"></span> 미확인</span>
										</td>
										@endif
									</tr>
									@endforeach
								</tbody>
							</table>
							<span class="label label-warning">출고내역</span>
							<table class="table table-condensed table-striped table-hover">
								<thead>
									<th>장비명</th>
									<th>대상</th>
									<th>날짜</th>
									<th>확인여부</th>
								</thead>
								<tbody>
									@foreach ($outbounds as $o)
									<tr>
										<td>{{ $o->item->code->title }}</td>
										<td>{{ $o->targetNode->node_name }}</td>
										<td>{{ $o->converted_date }}</td>
										@if ($o->is_confirmed == 1)
										<td>
											<span class="label label-success"><span class="glyphicon glyphicon-ok"></span> {{ $o->confirmed_date }}</span>
										</td>
										@else
										<td>
											<span class="label label-danger"><span class="glyphicon glyphicon-question-sign"></span> 미확인</span>
										</td>
										@endif
									</tr>
									@endforeach
								</tbody>
							</table>
						</div>
					</div>
				</div>
			</div>
			<!-- 수요조사 -->
			<div class="row">
				<div class="col-xs-12">
					<div class="panel panel-default">
						<div class="panel-heading">
							<h3 class="panel-title pull-left">
								<strong>진행중인 수요조사</strong>
							</h3>
							<a href="{{ url('equips/surveys') }}" class="label label-primary pull-right"> @lang('lastest.more') </a>
							<div class="clearfix"></div>
						</div>
						<div class="panel-body">
							<span class="label label-success">조사하기</span>
							<table class="table table-condensed table-striped table-hover">
								<thead>
									<tr>
										<th>장비명</th>
										<th>조사기한</th>
										<th>응답현황</th>
									</tr>
								</thead>
								@foreach ($surveys as $s)
								<tbody>
									<tr>
										<td>{{$s->item->code->title}}</td>
										<td>{{$s->started_at.'~'.$s->expired_at}}</td>
										<td>{{ $s->responses->count()/$s->item->types->count().'/'. $user->supplyNode->managedChildren->count()}} ({{$s->responses->count()/$user->supplyNode->managedChildren->count()*100}}%)</td>
									</tr>
								</tbody>
								@endforeach
							</table>
							<span class="label label-warning">조사응답</span>
							<table class="table table-condensed table-striped table-hover">
								<thead>
									<tr>
										<th>장비명</th>
										<th>조사기한</th>
										<th>응답여부</th>
									</tr>
								</thead>
								<tbody>
								@foreach ($toResponses as $r)
									<tr>
										<td>{{$r->item->code->title}}</td>
										<td>{{$r->started_at.'~'.$r->expired_at}}</td>
										<td>{{ $r->responses->count()/$r->item->types->count().'/'. $user->supplyNode->managedChildren->count()}} ({{$r->responses->count()/$user->supplyNode->managedChildren->count()*100}}%)</td>
									</tr>
								@endforeach
								</tbody>
							</table>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

@stop