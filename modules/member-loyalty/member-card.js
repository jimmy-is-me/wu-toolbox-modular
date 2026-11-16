document.querySelectorAll('.wutm-member-card-qr[data-qr]').forEach(function (element) {
    if (typeof QRCode === 'undefined' || element.dataset.ready) return;
    element.dataset.ready = '1';
    new QRCode(element, {text: element.dataset.qr, width: 180, height: 180, correctLevel: QRCode.CorrectLevel.M});
});
