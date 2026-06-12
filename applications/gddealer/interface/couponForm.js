document.addEventListener('DOMContentLoaded', function() {
    var form = document.querySelector('.gdCoupons__form');
    if (!form) return;

    var radios = form.querySelectorAll('input[name="discount_type"]');
    var valueField = document.getElementById('gdCouponValueField');
    if (!valueField) return;

    function sync() {
        var selected = form.querySelector('input[name="discount_type"]:checked');
        if (!selected) return;
        var input = valueField.querySelector('input[name="discount_value"]');
        if (selected.value === 'free_shipping') {
            valueField.style.display = 'none';
            if (input) { input.required = false; }
        } else {
            valueField.style.display = '';
            if (input) { input.required = true; }
        }
    }

    for (var i = 0; i < radios.length; i++) {
        radios[i].addEventListener('change', sync);
    }
    sync();
});
