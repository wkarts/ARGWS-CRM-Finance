(function(){
 'use strict';
 function mount(){
  var source=document.getElementById('pme-company-selector');if(!source)return;
  var menu=document.getElementById('menu'),profile=menu&&menu.querySelector('.sidebar-user-profile');
  if(profile){
   var card=document.createElement('div'),native=profile.querySelector('.profile');
   card.id=source.id;card.className=source.className;
   while(source.firstChild)card.appendChild(source.firstChild);
   source.remove();profile.insertBefore(card,profile.firstChild);
   if(native){
    var button=card.querySelector('.pme-company-toggle'),style=getComputedStyle(native);
    ['backgroundColor','color','borderColor','borderStyle','borderWidth','borderRadius','boxShadow','paddingTop','paddingRight','paddingBottom','paddingLeft'].forEach(function(key){button.style[key]=style[key];});
   }
  }
  var card=document.getElementById('pme-company-selector');
  card.addEventListener('submit',function(event){
   if(card.dataset.submitting==='1'){event.preventDefault();return;}
   card.dataset.submitting='1';card.setAttribute('aria-busy','true');
   // Do not disable the submitter: its company_id must remain in the native POST.
   card.querySelector('.pme-company-toggle').disabled=true;
  });
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount);else mount();
 window.addEventListener('pageshow',function(event){if(event.persisted)location.reload();});
})();
