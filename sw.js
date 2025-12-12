const CACHE_NAME = 'sam-store-v2'; // قمنا بتغيير الإصدار لتحديث الكاش
const urlsToCache = [
  './',                // الصفحة الرئيسية
  'index.php',
  'style.css',
  'icons/con-512x512.png' 
  // ملاحظة: حذفت offline.html و s.png لأنك إذا لم تكن تملكهم سيتوقف الملف عن العمل
  // إذا كنت تملكهم فعلاً، أعد إضافتهم للقائمة
];

// 1. التثبيت (Install)
self.addEventListener('install', (event) => {
  self.skipWaiting(); // تفعيل فوري وعدم الانتظار
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => {
        // نستخدم addAll بحذر، إذا فشل ملف واحد لن يتوقف الباقي
        return cache.addAll(urlsToCache).catch(err => {
            console.error('فشل تخزين بعض الملفات:', err);
        });
      })
  );
});

// 2. التنشيط (Activate)
self.addEventListener('activate', (event) => {
  event.waitUntil(
    Promise.all([
      self.clients.claim(), // السيطرة الفورية على الصفحات المفتوحة
      // تنظيف الكاش القديم
      caches.keys().then((cacheNames) => {
        return Promise.all(
          cacheNames.map((cacheName) => {
            if (cacheName !== CACHE_NAME) {
              return caches.delete(cacheName);
            }
          })
        );
      })
    ])
  );
});

// 3. جلب الملفات (Fetch Strategy) - استراتيجية الشبكة أولاً ثم الكاش
self.addEventListener('fetch', (event) => {
  // نستثني طلبات الـ API والـ POST من الكاش
  if (event.request.method !== 'GET' || event.request.url.includes('api.php') || event.request.url.includes('admin.php')) {
    return;
  }

  event.respondWith(
    fetch(event.request)
      .then((response) => {
        // إذا نجح الاتصال بالنت، نحدث الكاش نسخة جديدة
        let responseClone = response.clone();
        caches.open(CACHE_NAME).then((cache) => {
          cache.put(event.request, responseClone);
        });
        return response;
      })
      .catch(() => {
        // إذا انقطع النت، نأخذ من الكاش
        return caches.match(event.request);
      })
  );
});

// 4. استقبال الإشعار (Push)
self.addEventListener('push', function(event) {
    if (!(self.Notification && self.Notification.permission === 'granted')) {
        return;
    }

    const data = event.data ? event.data.json() : {};
    
    const options = {
        body: data.body || 'تفقد المنتجات الجديدة',
        icon: 'icons/con-512x512.png',  // تأكد أن الصورة موجودة بجانب الملف
        badge: 'icons/con-512x512.png', // أيقونة صغيرة للشريط العلوي
        data: { 
            url: data.url || self.registration.scope // الرابط الافتراضي هو الصفحة الرئيسية
        },
        vibrate: [100, 50, 100], // اهتزاز عند الوصول
        actions: [
            {action: 'explore', title: 'مشاهدة الآن'}
        ]
    };

    event.waitUntil(
        self.registration.showNotification(data.title || 'متجر سام', options)
    );
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    // فتح الرابط مباشرة دون أي فحص
    event.waitUntil(
        clients.openWindow(event.notification.data.url)
    );
});