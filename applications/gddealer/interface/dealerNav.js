(function(){
    document.addEventListener('click', function(e){
        var btn = e.target.closest('[data-gd-dropdown] .gdNavItem__toggle');
        if (!btn) return;
        var parent = btn.closest('[data-gd-dropdown]');
        var open = parent.classList.toggle('is-open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
})();
