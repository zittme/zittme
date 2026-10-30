var ncenterlite_need_highlight = true;
function ncenterlite_highlight() {
		var s = decodeURIComponent(location.href).replace(/.*#comment_/,'');
		if(!s || s === decodeURIComponent(location.href)) return;
		s = 'comment_' + s;
		jQuery('.xe_content').each(function(){
			var t = jQuery(this);
			if(t.hasClass(s) || (new RegExp(s + '_')).test(t.attr('class'))){
				t.css("display","block");
				if(this.animate && !(window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches)) {
					this.animate([{backgroundColor: "rgba(255,196,60,.22)"}, {backgroundColor: "rgba(255,196,60,0)"}], {duration: 1200, easing: "ease-out"});
				}
				ncenterlite_need_highlight = false;
			}
		});
}

if(typeof _viewSubComment == 'function') {
	old__viewSubComment = _viewSubComment;
	_viewSubComment = function(ret_obj) {
		old__viewSubComment(ret_obj);
		if(ncenterlite_need_highlight) {
			setTimeout(function(){
				var s = decodeURIComponent(location.href).match(/#.*comment_([0-9]+)/);
				if(s) {
					ncenterlite_highlight();
					location.href = '#social_comment_' + s[1];
				}
			}, 500);
		}
	};
}

jQuery(function(){
	ncenterlite_highlight();
});
