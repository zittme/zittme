/* ZZAN 에디터 — editor_common.js가 요구하는 표준 API. simpleeditor와 동일한 오버라이드 패턴. */
function _getZbedInstance(editor_sequence) {
    return jQuery('#edz_instance_' + editor_sequence);
}

function editorGetContent(editor_sequence) {
    var el = _getZbedInstance(editor_sequence).get(0);
    var html = (window.edzCleanHtml && el) ? window.edzCleanHtml(el) : _getZbedInstance(editor_sequence).html();
    return String(html).escape();
}

function editorReplaceHTML(iframe_obj, content) {
    var editor_sequence = parseInt(iframe_obj.id.replace(/^.*_/, ''), 10);
    _getZbedInstance(editor_sequence).html(content);
}

function editorGetIFrame(editor_sequence) {
    return _getZbedInstance(editor_sequence).get(0);
}
