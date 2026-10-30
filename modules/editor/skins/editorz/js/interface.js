/* ZZAN 에디터 — editor_common.js가 요구하는 표준 API. simpleeditor와 동일한 오버라이드 패턴. */
function _getZbedInstance(editor_sequence) {
    return jQuery('#edz_instance_' + editor_sequence);
}

function editorGetContent(editor_sequence) {
    // ckeditor/zitteditor 와 동일하게 raw HTML 을 그대로 반환한다.
    // .escape() 를 걸면 서버가 unescape 하지 않아 &lt;div&gt; 처럼 텍스트로 저장된다.
    var el = _getZbedInstance(editor_sequence).get(0);
    var html = (window.edzCleanHtml && el) ? window.edzCleanHtml(el) : _getZbedInstance(editor_sequence).html();
    return String(html);
}

function editorReplaceHTML(iframe_obj, content) {
    var editor_sequence = parseInt(iframe_obj.id.replace(/^.*_/, ''), 10);
    window.edzLoadHtml(_getZbedInstance(editor_sequence).get(0), content);
}

function editorGetIFrame(editor_sequence) {
    return _getZbedInstance(editor_sequence).get(0);
}
