
(function(){
  function ensureContainer(){
    var c = document.getElementById('toasts-container');
    if(!c){
      c = document.createElement('div');
      c.id = 'toasts-container';
      document.body.appendChild(c);
    }
    return c;
  }
  function mkToast(opts){
    var c = ensureContainer();
    var div = document.createElement('div');
    div.className = 'toast ' + (opts.severity || 'info');
    var close = document.createElement('span');
    close.className = 'close';
    close.textContent = '×';
    close.onclick = function(){ div.remove(); };
    div.appendChild(close);
    var title = document.createElement('div');
    title.className = 'title';
    title.textContent = opts.title || 'إشعار';
    div.appendChild(title);
    var msg = document.createElement('div');
    msg.className = 'msg';
    msg.innerHTML = (opts.message || '').replace(/\n/g,'<br>');
    div.appendChild(msg);
    if(opts.meta){
      var meta = document.createElement('div');
      meta.className = 'meta';
      meta.textContent = opts.meta;
      div.appendChild(meta);
    }
    c.appendChild(div);
    setTimeout(function(){ div.style.opacity='0'; setTimeout(function(){ div.remove(); }, 500); }, opts.ttl || 7000);
  }
  window.showToast = mkToast;
})();
