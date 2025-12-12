// متغير لتخزين بيانات السلة للمعاينة
let globalCartItems = [];

// --- 1. فتح وإغلاق السلة ---
function openCart() {
    document.getElementById('cartModal').style.display = 'flex';
    loadCartItems();
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

// متغيرات مؤقتة لحفظ المنتج المراد إضافته
let pendingPid = 0;
let pendingHasQty = false;
let pendingElemId = '';

// الدالة الأولى: الفحص
function checkSizeAndAdd(pid, hasQty, elemId, sizesStr) {
    // 1. إذا كان المنتج لا يحتوي على مقاسات (فارغ)
    if (!sizesStr || sizesStr.trim() === '') {
        addToCartFinal(pid, hasQty, elemId, null); // إضافة مباشرة بدون مقاس
        return;
    }

    // 2. إذا كان له مقاسات، نفتح النافذة
    pendingPid = pid;
    pendingHasQty = hasQty;
    pendingElemId = elemId;

    let sizesArr = sizesStr.split(',');
    let container = document.getElementById('sizesContainer');
    container.innerHTML = '';

    sizesArr.forEach(size => {
        size = size.trim();
        // إنشاء زر لكل مقاس
        let btn = document.createElement('button');
        btn.className = 'size-btn';
        btn.innerText = size;
        btn.onclick = function() {
            closeModal('sizeModal');
            addToCartFinal(pendingPid, pendingHasQty, pendingElemId, size);
        };
        container.appendChild(btn);
    });

    document.getElementById('sizeModal').style.display = 'flex';
}

// الدالة الثانية: التنفيذ الفعلي (استبدال addToCart القديمة)
function addToCartFinal(pid, hasQty, elemId, selectedSize) {
    if (!pid) return;

    // التحقق البصري من الكمية
    let qtyElem = document.getElementById(elemId);
    let currentQty = hasQty && qtyElem ? parseInt(qtyElem.innerText) : 0;
    if (hasQty && currentQty <= 0) { alert("نفذت الكمية!"); return; }

    let fd = new FormData();
    fd.append('action', 'add_to_cart');
    fd.append('product_id', pid);
    if (selectedSize) {
        fd.append('size', selectedSize); // إرسال المقاس المختار
    }

    document.body.style.cursor = 'wait';

    fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        document.body.style.cursor = 'default';
        if(data.status === 'success') {
            document.getElementById('cartCount').innerText = data.count;
            // إنقاص الرقم من الشاشة
            if (hasQty && qtyElem) {
                qtyElem.innerText = currentQty - 1;
                if (currentQty - 1 === 0) alert("تم حجز آخر قطعة!");
            }
            // رسالة تأكيد للمقاس (اختياري)
            // if(selectedSize) alert('تم إضافة مقاس ' + selectedSize);
        } else {
            alert(data.message);
        }
    })
    .catch(err => {
        document.body.style.cursor = 'default';
        console.error(err);
    });
}

// --- 3. تحميل عناصر السلة ---
// --- 3. تحميل عناصر السلة (التصميم الجديد) ---
function loadCartItems() {
    let fd = new FormData();
    fd.append('action', 'get_cart');

    fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(items => {
        globalCartItems = items;
        let html = '', invHtml = '', total = 0;

        items.forEach((item, index) => {
            let price = item.discount_price ? item.discount_price : item.price;
            total += price * item.qty;

            // تجهيز نص المقاس
            let sizeDisplay = item.size ? `<span class="cart-size"> (مقاس: ${item.size})</span>` : '';
            let sizeForInvoice = item.size ? ` (${item.size})` : '';

            // --- الهيكل الجديد للبطاقة ---
            html += `
            <div class="cart-item">
                <!-- 1. الصورة (يمين) -->
                <img src="uploads/${item.image}" onclick="previewProduct(${index})">

                <!-- 2. التفاصيل (وسط) -->
                <div class="cart-details">
                    <div class="cart-name">${item.name} ${sizeDisplay}</div>
                    <div class="cart-price">${price} ر.ي</div>
                </div>

                <!-- 3. التحكم (يسار) -->
                <div class="cart-actions">
                    <!-- أزرار الكمية (كبسولة) -->
                    <div class="qty-group">
                        <button class="qty-btn" onclick="updateQty(${item.cart_id}, 'increase')">+</button>
                        <span class="qty-num">${item.qty}</span>
                        <button class="qty-btn" onclick="updateQty(${item.cart_id}, 'decrease')">-</button>
                    </div>

                    <!-- أزرار الحذف والمعاينة -->
                    <div class="tools-group">
                        <button class="tool-btn view" onclick="previewProduct(${index})" title="معاينة">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                        <button class="tool-btn delete" onclick="removeFromCart(${item.cart_id})" title="حذف">
                            <i class="fa-solid fa-trash-can"></i>
                        </button>
                    </div>
                </div>
            </div>`;

            // جدول الفاتورة (مخفي)
            invHtml += `<tr>
                            <td style="padding:5px;">${item.name} ${sizeForInvoice}</td>
                            <td>${price}</td>
                            <td>${item.qty}</td>
                        </tr>`;
        });

        // في حال السلة فارغة
        if (items.length === 0) {
            html = `
            <div style="text-align:center; padding:40px 20px; color:#888;">
                <i class="fa-solid fa-cart-arrow-down" style="font-size:3rem; margin-bottom:10px; color:#ddd;"></i>
                <p>سلة المشتريات فارغة</p>
            </div>`;
        }

        document.getElementById('cartItems').innerHTML = html;
        document.getElementById('invBody').innerHTML = invHtml;
        document.getElementById('invTotal').innerText = total;
    });
}

// --- 4. تحديث كمية السلة ---
function updateQty(id, op) {
    let fd = new FormData();
    fd.append('action', 'update_cart_qty');
    fd.append('cart_id', id);
    fd.append('operation', op);

    fetch('api.php', {method:'POST', body:fd})
    .then(r=>r.json())
    .then(d=>{
        if (d.status === 'success') {
            loadCartItems();
            document.getElementById('cartCount').innerText = d.count;
        } else {
            alert(d.message); // رسالة الخطأ عند تجاوز الحد
        }
    });
}

// --- 5. حذف من السلة ---
function removeFromCart(id) {
    let fd = new FormData();
    fd.append('action', 'remove_from_cart');
    fd.append('cart_id', id);
    fetch('api.php', {method:'POST', body:fd}).then(r=>r.json()).then(d=>{
        loadCartItems();
        document.getElementById('cartCount').innerText = d.count;
    });
}

// --- 6. معاينة المنتج (Modal) ---
// --- 6. معاينة المنتج (تصميم احترافي) ---
function previewProduct(index) {
    let item = globalCartItems[index];
    if (!item) return;

    let price = item.discount_price ? item.discount_price : item.price;
    // تنسيق الوصف: إذا فارغ نكتب رسالة لطيفة
    let desc = item.description ? item.description.replace(/\n/g, "<br>") : "لا يوجد وصف إضافي لهذا المنتج.";

    // عرض المقاس إن وجد
    let sizeHtml = item.size ? `<span style="display:block; font-size:0.9rem; color:#1a2a3a; margin-bottom:5px;">المقاس المختار: <b style="color:#d00000">${item.size}</b></span>` : '';

    let html = `
        <div class="preview-container">
            <!-- الصورة -->
            <div class="preview-img-box">
                <img src="uploads/${item.image}" alt="${item.name}">
            </div>

            <!-- التفاصيل -->
            <div class="preview-title">${item.name}</div>
            ${sizeHtml}
            <div class="preview-price">${price} ر.ي</div>

            <div class="preview-desc">
                <strong>📝 الوصف:</strong><br>
                ${desc}
            </div>

            <!-- الأزرار -->
            <div class="preview-actions">
                <button onclick="closeModal('previewModal')" class="btn-modal btn-close-action">
                    إغلاق
                </button>

                <button onclick="removeFromCart(${item.cart_id}); closeModal('previewModal');" class="btn-modal btn-remove-action">
                    <i class="fa-solid fa-trash-can"></i> حذف من السلة
                </button>
            </div>
        </div>
    `;
    document.getElementById('previewContent').innerHTML = html;
    document.getElementById('previewModal').style.display = 'flex';
}

// --- 7. الشراء (Checkout) مع واتساب ---
function checkout() {
    if (globalCartItems.length === 0) {
        alert("السلة فارغة!"); return;
    }

    let name = document.getElementById('custName').value.trim();
    let phone = document.getElementById('custPhone').value.trim();
    // جلب القيم الجديدة
    let address = document.getElementById('custAddress').value.trim();
    let notes = document.getElementById('custNotes').value.trim();

    // التحقق من المدخلات
    if(name.split(' ').length < 2) { alert('اكتب الاسم الثنائي'); return; }
    // if(phone.length < 6) { alert('رقم الهاتف غير صحيح'); return; }
    if(address.length < 2) { alert('يرجى كتابة العنوان بشكل واضح'); return; }

    let btn = document.getElementById('btnCheckout');
    let orgText = btn.innerText;
    btn.disabled = true;
    btn.innerText = "جاري المعالجة...";

    let fd = new FormData();
    fd.append('action', 'checkout');
    fd.append('name', name);
    fd.append('phone', phone);
    // إرسال البيانات الجديدة
    fd.append('address', address);
    fd.append('notes', notes);

    // رقم هاتفك للواتساب
    const myPhoneNumber = "967738183179";

    fetch('api.php', {method:'POST', body:fd})
    .then(r=>r.json())
    .then(d=>{
        if(d.status==='success'){
            // --- تعبئة الفاتورة بالبيانات الجديدة ---
            document.getElementById('invName').innerText = name;

            // سنقوم بإضافة العنوان ورقم الهاتف للفاتورة بإنشاء عناصر HTML لها
            // تأكد أنك ستضيف هذه العناصر في HTML الفاتورة أدناه (الخطوة 4)
            document.getElementById('invPhone').innerText = phone;
            document.getElementById('invAddress').innerText = address;
            // document.getElementById('invId').innerText = d.order_id;
            document.getElementById('invId').innerText = d.invoice_code;
            document.getElementById('invoice-area').style.display='block';

            html2canvas(document.getElementById('invoice-area')).then(canvas => {
                let imgData = canvas.toDataURL('image/png');

                // رفع الصورة
                let uploadFd = new FormData();
                uploadFd.append('action', 'save_invoice_image');
                uploadFd.append('image', imgData);

                fetch('api.php', {method:'POST', body:uploadFd})
                .then(res => res.json())
                .then(resData => {
                    if(resData.status === 'success') {
                        let currentUrl = window.location.href.substring(0, window.location.href.lastIndexOf('/'));
                        let fileUrl = currentUrl + "/invoices/" + resData.file;

                        // إضافة العنوان للرسالة
                        let msg = `طلب جديد من: ${name}\n📱 الهاتف: ${phone}\n📍 العنوان: ${address}\n📝 ملاحظات: ${notes}\n📄 الفاتورة: ${fileUrl}`;

                        let whatsappUrl = `https://wa.me/${myPhoneNumber}?text=${encodeURIComponent(msg)}`;

                        // تنزيل الصورة للزبون
                        let link = document.createElement('a');
                        link.download = 'SAM_Invoice_' + Date.now() + '.png';
                        link.href = imgData;
                        link.click();

                        window.open(whatsappUrl, '_blank');
                        setTimeout(() => { window.location.reload(); }, 1000);
                    }
                });
                document.getElementById('invoice-area').style.display='none';
            });
        } else {
            alert(d.message);
            btn.disabled = false;
            btn.innerText = orgText;
        }
    })
    .catch(e => { console.error(e); alert("خطأ"); btn.disabled = false; btn.innerText = orgText; });
}

// --- عند العودة من صفحة أخرى ---
window.onload = function() {
    const u = new URLSearchParams(window.location.search);
    if(u.get('open_cart')==='1') {
        openCart();
        window.history.replaceState({},document.title,"index.php");
    }
};
// دالة فتح وإغلاق قائمة الأقسام
function toggleCatMenu() {
    let menu = document.getElementById('catsMenu');
    if (menu.style.display === 'block') {
        menu.style.display = 'none';
    } else {
        menu.style.display = 'block';
    }
}
// إغلاق القائمة عند الضغط في أي مكان خارجها
 window.addEventListener('click', function(e) {
    let menu = document.getElementById('catsMenu');
    // نبحث عن أقرب عنصر nav-item تم ضغطه (لأن الأيقونة داخل div)
    let clickedNavItem = e.target.closest('.nav-item');

    // التحقق: إذا كانت القائمة مفتوحة
    if (menu.style.display === 'block') {
        // إذا لم نضغط داخل القائمة، ولم نضغط على زر القائمة (الأول في الشريط)
        if (!menu.contains(e.target)) {
            // التأكد أن الضغط لم يكن على أيقونة القائمة نفسها
            // زر القائمة هو العنصر الأول الذي يستدعي toggleCatMenu
            if (!clickedNavItem || !clickedNavItem.onclick) {
                menu.style.display = 'none';
            }
        }
    }
});
function toggleFav(btn, pid) {
    let icon = btn.querySelector('i'); // الوصول للأيقونة داخل الزر

    let fd = new FormData();
    fd.append('action', 'toggle_favorite');
    fd.append('product_id', pid);

    fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'added') {
            btn.classList.add('active');
            // تغيير الأيقونة إلى قلب ممتلئ
            icon.classList.remove('fa-regular');
            icon.classList.add('fa-solid');
            icon.classList.add('fa-heart');
        } else {
            btn.classList.remove('active');
            // تغيير الأيقونة إلى قلب فارغ
            icon.classList.remove('fa-solid');
            icon.classList.add('fa-regular');
            icon.classList.add('fa-heart');
        }
    })
    .catch(e => console.error(e));
}
// --- حل مشكلة تحديث الصفحة عند العودة من الخلف ---
window.addEventListener( "pageshow", function ( event ) {
  // الـ history.navigationMode deprecated ولكن هذا الفحص يعمل في معظم المتصفحات
  // للتحقق مما إذا كانت الصفحة مخزنة في الـ Cache
  var historyTraversal = event.persisted ||
                         ( typeof window.performance != "undefined" &&
                              window.performance.navigation.type === 2 );
  if ( historyTraversal ) {
    // إعادة تحميل الصفحة لجلب حالة المفضلة الجديدة من قاعدة البيانات
    window.location.reload();
  }
});
function getLocation() {
    let addressField = document.getElementById("custAddress");

    if (navigator.geolocation) {
        addressField.value = "جاري جلب إحداثياتك... 📡";
        document.body.style.cursor = 'wait';

        // طلب دقة عالية (High Accuracy)
        navigator.geolocation.getCurrentPosition(showPosition, showError, {
            enableHighAccuracy: true, // محاولة الحصول على أدق موقع ممكن (GPS)
            timeout: 10000,
            maximumAge: 0
        });
    } else {
        alert("المتصفح لا يدعم تحديد الموقع.");
    }
}

function showPosition(position) {
    document.body.style.cursor = 'default';
    let lat = position.coords.latitude;
    let long = position.coords.longitude;

    // رابط يفتح تطبيق الخرائط مباشرة
    let googleMapsLink = `https://maps.google.com/?q=${lat},${long}`;

    // وضع الرابط في الحقل
    let field = document.getElementById("custAddress");
    field.value = googleMapsLink;

    // وميض للحقل لتأكيد العملية
    field.style.borderColor = "#28a745";
    setTimeout(() => { field.style.borderColor = "#ddd"; }, 2000);
}

function showError(error) {
    document.body.style.cursor = 'default';
    let field = document.getElementById("custAddress");
    field.value = ""; // تفريغ الحقل
    field.placeholder = "تعذر تحديد الموقع، اكتب العنوان يدوياً";

    switch(error.code) {
        case error.PERMISSION_DENIED:
            alert("يجب السماح للموقع بالوصول للموقع الجغرافي من إعدادات المتصفح.");
            break;
        case error.POSITION_UNAVAILABLE:
            alert("معلومات الموقع غير متوفرة (تأكد من تشغيل GPS).");
            break;
        case error.TIMEOUT:
            alert("انتهت مهلة الانتظار.");
            break;
        default:
            alert("حدث خطأ غير معروف.");
    }
}
// تسجيل Service Worker
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('service-worker.js');
            });
        }

        // منطق زر التثبيت
        let deferredPrompt;
        const pwaBanner = document.getElementById('pwa-install-banner');
        const installBtn = document.getElementById('pwa-install-btn');

        window.addEventListener('beforeinstallprompt', (e) => {
            // منع ظهور النافذة الافتراضية للمتصفح فوراً
            e.preventDefault();
            deferredPrompt = e;
            // إظهار الشريط الخاص بنا
            pwaBanner.style.display = 'flex';
        });

        installBtn.addEventListener('click', async () => {
            if (deferredPrompt) {
                deferredPrompt.prompt(); // إظهار نافذة التثبيت الرسمية
                const { outcome } = await deferredPrompt.userChoice;
                if (outcome === 'accepted') {
                    console.log('User accepted the install prompt');
                }
                deferredPrompt = null;
                pwaBanner.style.display = 'none'; // إخفاء الشريط بعد الضغط
            }
        });

        // إذا كان التطبيق مثبتاً بالفعل، لا تظهر الشريط
        window.addEventListener('appinstalled', () => {
            pwaBanner.style.display = 'none';
        });
