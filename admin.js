
function openTab(evt, name) {
    var i, x = document.getElementsByClassName("tab-content"), b = document.getElementsByClassName("tab-btn");
    for (i = 0; i < x.length; i++) {
        x[i].style.display = "none";
    }
    for (i = 0; i < b.length; i++) {
        b[i].className = b[i].className.replace(" active", "");
    }
    document.getElementById(name).style.display = "block";
    if (evt) {
        evt.currentTarget.className += " active";
    }
    const url = new URL(window.location);
    url.searchParams.set('tab', name);
    window.history.pushState({}, '', url);
}

window.onload = function() {
    const t = new URLSearchParams(window.location.search).get('tab');
    if (t) {
        let btnId = 'btn-' + t.replace('tab-', '');
        let btn = document.getElementById(btnId);
        if (btn) {
            btn.click();
        }
    }
};

function openModal(id) {
    document.getElementById(id).style.display = 'flex';
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

function openEditCat(c) {
    document.getElementById('edit_cat_id').value = c.id;
    document.getElementById('edit_cat_name').value = c.name;
    openModal('editCatModal');
}

function openEditProd(p) {
    document.getElementById('edit_prod_id').value = p.id;
    document.getElementById('edit_prod_name').value = p.name;
    document.getElementById('edit_prod_desc').value = p.description;
    document.getElementById('edit_prod_sizes').value = p.sizes;
    document.getElementById('edit_prod_price').value = p.price;
    document.getElementById('edit_prod_disc').value = p.discount_price;
    document.getElementById('edit_prod_qty').value = p.quantity;
    document.getElementById('edit_prod_note').value = p.admin_note;
    document.getElementById('edit_prod_cat').value = p.category_id;
    document.getElementById('edit_prod_supplier').value = p.supplier;
    openModal('editProdModal');
}

function validatePrice(form) {
    let price = parseFloat(form.querySelector('input[name="price"]').value);
    let discIn = form.querySelector('input[name="discount"]').value;
    if (discIn.trim() !== "") {
        if (parseFloat(discIn) >= price) {
            alert("تنبيه: سعر الخصم يجب أن يكون أقل من السعر الرسمي!");
            return false;
        }
    }
    return true;
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
};
if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
            navigator.serviceWorker.register('admin-sw.js');
        });
    }