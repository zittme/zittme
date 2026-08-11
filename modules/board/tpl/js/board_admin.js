/**
 * @file   modules/board/js/board_admin.js
 * @author NHN (developers@xpressengine.com)
 * @brief  board 모듈의 관리자용 javascript
 **/
/* complete to insert board module */
function completeInsertBoard(ret_obj)
{
	var error = ret_obj.error;
	var message = ret_obj.message;

	var page = ret_obj.page;
	var module_srl = ret_obj.module_srl;

	alert(message);

	var url = current_url.setQuery('act','dispBoardAdminBoardInfo');
	if(module_srl) url = url.setQuery('module_srl',module_srl);
	if(page) url.setQuery('page',page);
	location.href = url;
}

/* delete the board module*/
function completeDeleteBoard(ret_obj)
{
	var error = ret_obj.error;
	var message = ret_obj.message;
	var page = ret_obj.page;
	alert(message);

	var url = current_url.setQuery('act','dispBoardAdminContent').setQuery('module_srl','');
	if(page) url = url.setQuery('page',page);
	location.href = url;
}

/* update category */
function doUpdateCategory(category_srl, mode, message)
{
	if(typeof(message)!='undefined'&&!confirm(message)) return;

	var fo_obj = document.getElementById('fo_category_info');
	fo_obj.category_srl.value = category_srl;
	fo_obj.mode.value = mode;

	procFilter(fo_obj, update_category);
}

/* change category */
function completeUpdateCategory(ret_obj)
{
	var error = ret_obj.error;
	var message = ret_obj.message;
	var module_srl = ret_obj.module_srl;
	var page = ret_obj.page;
	alert(message);

	var url = current_url.setQuery('module_srl',module_srl).setQuery('act','dispBoardAdminCategoryInfo');
	if(page) url.setQuery('page',page);
	location.href = url;
}

/* setup all*/
function doCartSetup(url)
{
	var module_srl = [];
	jQuery('#fo_list input[name=cart]:checked').each(function()
	{
		module_srl[module_srl.length] = jQuery(this).val();
	});

	if(module_srl.length<1) return;

	url += "&module_srls="+module_srl.join(',');
	popopen(url,'modulesSetup');
}

/* setup index */
function doInsertItem()
{
	var target_obj = document.getElementById('targetItem');
	var display_obj = document.getElementById('displayItem');
	if(!target_obj || !display_obj) return;

	var text = target_obj.options[target_obj.selectedIndex].text;
	var value = target_obj.options[target_obj.selectedIndex].value;

	for(var i=0;i<display_obj.options.length;i++) if(display_obj.options[i].value == value) return;

	var obj = new Option(text, value, true, true);
	display_obj.options[display_obj.options.length] = obj;

}
function doDeleteItem()
{
	var sel_obj = document.getElementById('displayItem');
	var idx = sel_obj.selectedIndex;
	if(idx<0 || sel_obj.options.length<2) return;
	sel_obj.remove(idx);
	sel_obj.selectedIndex = idx-1;
}
function doMoveUpItem()
{
	var sel_obj = document.getElementById('displayItem');
	var idx = sel_obj.selectedIndex;
	if(idx<1 || !idx) return;

	var text = sel_obj.options[idx].text;
	var value = sel_obj.options[idx].value;

	sel_obj.options[idx].text = sel_obj.options[idx-1].text;
	sel_obj.options[idx].value = sel_obj.options[idx-1].value;
	sel_obj.options[idx-1].text = text;
	sel_obj.options[idx-1].value = value;
	sel_obj.selectedIndex = idx-1;
}
function doMoveDownItem()
{
	var sel_obj = document.getElementById('displayItem');
	var idx = sel_obj.selectedIndex;
	if(idx>=sel_obj.options.length-1) return;

	var text = sel_obj.options[idx].text;
	var value = sel_obj.options[idx].value;

	sel_obj.options[idx].text = sel_obj.options[idx+1].text;
	sel_obj.options[idx].value = sel_obj.options[idx+1].value;
	sel_obj.options[idx+1].text = text;
	sel_obj.options[idx+1].value = value;
	sel_obj.selectedIndex = idx+1;
}

function doSaveListConfig(module_srl)
{
	if(!module_srl) return;
	var sel_obj = document.getElementById('displayItem');
	var idx = sel_obj.selectedIndex;

	var list = [];
	for(var i=0;i<sel_obj.options.length;i++) list[list.length] = sel_obj.options[i].value;
	if(list.length<1) return;

	var params = {};
	params.module_srl = module_srl;
	params.list = list.join(',');

	var response_tags = new Array('error','message');

	exec_json('board.procBoardAdminInsertListConfig', params, function() { location.reload(); });
}

$(function() {
	// 뷰 모드에 따라 어떤 설정이 의미를 갖는지가 달라진다.
	//   모바일 전용 뷰 : 모바일 스킨·레이아웃 + 좁은 화면 설정값 모두 사용
	//   반응형 뷰      : PC 스킨을 그대로 쓰므로 모바일 스킨·레이아웃은 무의미하고,
	//                    목록 수·페이지 수 같은 설정값만 좁은 화면에서 적용된다
	//   사용 안 함     : 둘 다 해당 없음
	// '전역 설정 따름'은 서버가 결정하므로 여기서는 둘 다 보여 준다.
	var $mode = $('#use_mobile');

	function syncViewModeFields() {
		var mode = $mode.val();
		var showSkin = (mode === 'Y' || mode === '');
		var showNarrow = (mode === 'Y' || mode === 'R' || mode === '');

		$('.hide-if-not-mobile-view').toggle(showSkin || showNarrow);
		$('.zm-mskin-fields').toggle(showSkin);
		$('.zm-narrow-fields').toggle(showNarrow);
	}

	if ($mode.length) {
		$mode.on('change', syncViewModeFields);
		syncViewModeFields();
	}
});
