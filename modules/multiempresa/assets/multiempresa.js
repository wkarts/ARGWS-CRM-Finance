(function () {
  'use strict';
  var node=document.getElementById('multiempresa-routing');
  if(!node||!window.fetch)return;
  fetch(node.dataset.health,{credentials:'same-origin',cache:'no-store'}).then(function(r){if(!r.ok)throw new Error();return r.json();}).then(function(d){if(!d.ready||d.module!=='multiempresa')throw new Error();}).catch(function(){
    node.className='alert alert-warning';
    node.textContent='Não foi possível verificar o acesso às empresas. O cadastro continua disponível. Consulte o diagnóstico nas configurações globais antes de abrir um ambiente.';
  });
})();
