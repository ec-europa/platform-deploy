!function(){"use strict";function e(){var e=this,t=jQuery(".splash-page--overlay"),a=".splash-page--overlay",n=".splash-page__btn-close",l="body";jQuery(".lang-select-site").on("click","a.lang-select-site__link",function(r){t.find(n).length||jQuery.get(jQuery(e).attr("href"),function(e){var a=jQuery(jQuery.parseHTML(e));t.html(a.find(".page-content"))}),jQuery(a).show(),jQuery(l).addClass("disablescroll");var s=function(){jQuery(a).hide(),jQuery(l).removeClass("disablescroll")};t.on("click",n,function(e){s(),e.preventDefault()}),jQuery(document).keyup(function(e){27===e.keyCode&&s()}),r.preventDefault()})}Drupal.behaviors.europa_lang_select_site={attach:e}}();
//# sourceMappingURL=lang-select-site.js.map