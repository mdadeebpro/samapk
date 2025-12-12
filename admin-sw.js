// admin-sw.js
// هذا الملف بسيط جداً وهدفه فقط جعل المتصفح يقبل تثبيت الموقع كتطبيق
// نحن لا نقوم بتخزين أي شيء هنا (No Caching) لضمان رؤية الطلبات الجديدة دائماً

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(clients.claim());
});

self.addEventListener('fetch', (event) => {
    // لا تفعل شيئاً، اترك الاتصال يذهب للسيرفر مباشرة
    // هذا يضمن أنك ترى أحدث البيانات دائماً
});