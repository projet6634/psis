@extends('layouts.master')


@section('content')
<div class="panel panel-default">
	<div class="panel-body">
		<div class="row">
			<div class="col-xs-12">
				<div class="page-header" id="report_title_container">
					<h3><small>제목</small> 
						<input type="text" id="report_title" class="form-control col-xs-12 input-sm" value="{{ $report->title or '' }}">
					</h3>
					<br>
					@if ($mode == 'edit') 
						<h3>
							<div class="row">
								<div class="col-xs-4">
									<small>작성처</small> {{ $report->department->full_name }} 
								</div>
								<div class="col-xs-4">
									<small>작성자</small> {{ $report->user->user_name }}
								</div>
								<div class="col-xs-4">
									<small>작성시간</small> {{ $report->created_at->format('Y-m-d h:i') }}
								</div>
							</div>
						</h3>
						<input type="hidden" id="current_report_id" value="{{ $report->id }}">
					@endif
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-xs-10">
				<input type="hidden" id="hwpctrl_content" value="{{ isset($report)?$report->histories()->lastest()->first()->content:(isset($template)?$template->content:'') }}">
				<input type="hidden" id="report_compose_mode" value="{{ $mode  }}">
				<object id="HwpCtrl" height="800" width="100%" align="center" classid="clsid:BD9C32DE-3155-4691-8972-097D53B10052">
	                <param name="TOOLBAR_MENU" value="true">
	                <param name="TOOLBAR_STANDARD" value="true">
	                <param name="TOOLBAR_FORMAT" value="true">
	                <param name="TOOLBAR_DRAW" value="false">
	                <param name="TOOLBAR_TABLE" value="false">
	                <param name="TOOLBAR_IMAGE" value="false">
	                <param name="TOOLBAR_HEADERFOOTER" value="false">
	                <param name="SHOW_TOOLBAR" value="true">

	                <div class="alert alert-warning">
	                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
	                    <strong>ActiveX 미설치</strong> HwpCtrl이 설치되지 않아서 보고서를 조회할 수 없습니다.
	                </div>
				</object>
			</div>
			<div class="col-xs-2">
				<h5>작업</h5>
				<div class="btn-group-vertical report-toolbar">
					<button type="button" class="btn btn-primary btn-block" id="report_submit">
						<small><span class="glyphicon glyphicon-ok"></span> 제출</small>
					</button>
					<button type="button" class="btn btn-info btn-block" id="report_save_draft">
						<small><span class="glyphicon glyphicon-floppy-disk"></span> 임시저장</small>
					</button>
					@if ($user->isSuperUser() or $user->hasAccess('addReportForm'))
					<button type="button" class="btn btn-success btn-block" id="report_create_template">
						<small><span class="glyphicon glyphicon-floppy-disk"></span> 속보양식저장</small>
					</button>
					@endif
					<button type="button" class="btn btn-default btn-block" onclick="window.history.back()">
						<small><span class="glyphicon glyphicon-remove"></span> 취소</small>
					</button>
				</div>
				<div class="btn-group-vertical report-toolbar">
					<button class="btn btn-block btn-default" id="select_report_template_btn">
						<small><span class="glyphicon glyphicon-book"></span> 속보양식선택</small>
					</button>
					<button class="btn btn-block btn-default" id="select_report_drafts_btn">
						<small><span class="glyphicon glyphicon-folder-open"></span> 불러오기</small>
					</button>
				</div>
			</div>
		</div>
	</div>
</div>

@stop


@section('styles')
<style type="text/css" media="screen">
	#report_title_container {
		margin-top: 0;
	}
	.report-toolbar {
		width: 100%;
		margin-bottom: 10px;
	}
</style>
@stop

@section('scripts')
{{ HTML::script('static/js/hwpctrl.js') }}
<script type="text/javascript">
$(function() {

    if (initHwpCtrl()) {
        var data = $("#hwpctrl_content").val();
    	vHwpCtrl.SetTextFile(data, "HWP", "");
    }

	$("#report_delete").click(function(){
		if (!confirm('정말 삭제하시겠습니까?')) {
			return;
		}

		var report_id = $("#current_report_id").val();

		$.ajax({
			url: base_url+"/reports/delete",
			type: "post",
			data: { rid:report_id },
			success: function(response) {
				alert(response.message);
				if (response.result == 0) {
					redirect(base_url+"/reports/list");
				}
			}
		});
	});

	$("#report_submit").click(function() {
		var mode = $("#report_compose_mode").val();

		var title = $("#report_title").val();
		if (!title) {
			alert('제목을 입력해주세요');
			return;
		}
		var content = vHwpCtrl.GetTextFile("HWP", "");
		var params = {
			title: title,
			content: content
		};

		var url;
		switch (mode) {
			case 'edit':
				params.rid = $("#current_report_id").val();
				url = base_url+"/reports/edit";
			break;
			case 'create':
				url = base_url+"/reports/create";
			break;
			default:
			return;
		}
		$.ajax({
			url: url,
			type: "post",
			data: params,
			success: function(res) {
				alert(res.message);
				if (res.result == 0) {
					redirect(res.url);
				}
			}
		});
	});

	$("#report_save_draft").click(function() {

		var title = $("#report_title").val();
		var content = vHwpCtrl.GetTextFile("HWP", "");
		var params = {
			title: title,
			content: content
		};

		$.ajax({
			url: base_url+"/reports/drafts/save",
			type: "post",
			data: params,
			success: function(res) {
				alert(res.message);
			}
		});
	});

	$("#select_report_template_btn").click(function() {
		popup(base_url+"/reports/templates", 300, 400);
	});

	$("#select_report_drafts_btn").click(function() {
		popup(base_url+"/reports/drafts", 600, 800);
	});

	$("#report_create_template").click(function() {

		var title = $("#report_title").val();
		var content = vHwpCtrl.GetTextFile("HWP", "");
		var params = {
			title: title,
			content: content
		};

		$.ajax({
			url: base_url+"/reports/templates/save",
			type: "post",
			data: params,
			success: function(res) {
				alert(res.message);
			}
		});
	});
});

function onTemplateSelected(template) {
	vHwpCtrl.SetTextFile(template.content, "HWP", "");
}

function onDraftSelected(draft) {
	$("#report_title").val(draft.title);
	vHwpCtrl.SetTextFile(draft.content, "HWP", "");
}
</script>
@stop
