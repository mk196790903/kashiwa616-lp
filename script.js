const hamburger = document.querySelector(".hamburger");
const navListWrapper = document.querySelector(".nav-inner");
const navList = document.querySelector(".nav-tools ul");
const navCloseBtn = document.querySelector('#nav-close-btn')


hamburger.addEventListener("click", () => {
  navListWrapper.classList.add("toggleNavWrapper");
  navList.classList.add("toggleNav");
  navCloseBtn.classList.add("active-cross-btn")
});

navCloseBtn.addEventListener("click", ()=> {
  navListWrapper.classList.remove("toggleNavWrapper");
  navList.classList.remove("toggleNav");
  navCloseBtn.classList.remove("active-cross-btn")
})

document.querySelectorAll(".nav-links li a").forEach(link => {
  link.addEventListener("click", () => {
    navListWrapper.classList.remove("toggleNavWrapper");
    navList.classList.remove("toggleNav");
    navCloseBtn.classList.remove("active-cross-btn");
  });
});


const observer = new IntersectionObserver(entries => {
  entries.forEach(entry => {
   if (entry.isIntersecting) {
  entry.target.classList.add("animate");
} 
  });
}, {
  threshold: 0.2
});

document.querySelectorAll(".fade-section").forEach((elem,) => {
  observer.observe(elem);
});



// Email Submission functionality removed



/* ============================================================
   616 お問い合わせフォーム - バリデーション & 送信処理
   reCAPTCHA v3 + 多層スパム対策
   ============================================================ */
(function() {
  'use strict';

  var CONFIG = {
    RECAPTCHA_SITE_KEY: '6LexHo0sAAAAAMFv_ODQJfuexz_bgHhwOmWm1S0E',
    PHP_ENDPOINT: '/lp/send-mail.php',
    MIN_SUBMIT_TIME: 5,
    MAX_SUBMIT_TIME: 1800
  };

  var DISPOSABLE_DOMAINS = [
    'mailinator.com','guerrillamail.com','tempmail.com','throwaway.email',
    'yopmail.com','sharklasers.com','guerrillamailblock.com','grr.la',
    'dispostable.com','maildrop.cc','10minutemail.com','trashmail.com',
    'temp-mail.org','fakeinbox.com','mailnesia.com','tmpmail.net',
    'getnada.com','emailondeck.com','mohmal.com','minuteinbox.com'
  ];

  var form = document.getElementById('f616ContactForm');
  if (!form) return;

  var formArea = document.getElementById('f616FormArea');
  var completeScreen = document.getElementById('f616CompleteScreen');
  var inquiryNumberEl = document.getElementById('f616InquiryNumber');
  var globalError = document.getElementById('f616GlobalError');
  var submitBtn = document.getElementById('f616SubmitBtn');
  var consentCb = document.getElementById('f616_consent');

  /* ============================================================
     納品場所（複数選択）: カスタムプルダウン（選択内容を表示）
     - 元の<select>に同期するため送信形式は変更しない
     ============================================================ */
  function setupMultiSelectFromNative(selectId, placeholderText) {
    var selectEl = document.getElementById(selectId);
    if (!selectEl) return;

    // 既に初期化済みなら何もしない
    if (selectEl.dataset.f616MultiselectInitialized === '1') return;
    selectEl.dataset.f616MultiselectInitialized = '1';

    // 表示用UI
    var wrapper = document.createElement('div');
    wrapper.className = 'f616-multiselect';
    wrapper.id = selectId + '_ms';

    var trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'f616-ms-trigger';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.setAttribute('data-f616-error-proxy', selectId);

    var triggerText = document.createElement('span');
    triggerText.className = 'f616-ms-trigger-text';
    triggerText.textContent = placeholderText || '選択してください';
    trigger.appendChild(triggerText);

    var panel = document.createElement('div');
    panel.className = 'f616-ms-panel';
    panel.setAttribute('role', 'listbox');
    panel.setAttribute('aria-multiselectable', 'true');
    panel.hidden = true;

    function getSelectedLabels() {
      var labels = [];
      Array.prototype.forEach.call(selectEl.options, function(opt) {
        if (opt.selected && opt.value) labels.push(opt.textContent);
      });
      return labels;
    }

    function updateTriggerText() {
      var labels = getSelectedLabels();
      if (labels.length === 0) {
        triggerText.textContent = placeholderText || '選択してください';
        wrapper.classList.remove('has-value');
        return;
      }
      triggerText.textContent = labels.join('、');
      wrapper.classList.add('has-value');
    }

    // option -> checkbox
    Array.prototype.forEach.call(selectEl.options, function(opt) {
      if (!opt.value) return;

      var optionLabel = document.createElement('label');
      optionLabel.className = 'f616-ms-option';

      var cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.className = 'f616-ms-checkbox';
      cb.value = opt.value;
      cb.checked = !!opt.selected;

      var text = document.createElement('span');
      text.className = 'f616-ms-option-text';
      text.textContent = opt.textContent;

      cb.addEventListener('change', function() {
        opt.selected = cb.checked;
        updateTriggerText();
        // hidden select の change も発火させて、必要なら外部の処理に合わせる
        selectEl.dispatchEvent(new Event('change', { bubbles: true }));
      });

      optionLabel.appendChild(cb);
      optionLabel.appendChild(text);
      panel.appendChild(optionLabel);
    });

    function openPanel() {
      panel.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      wrapper.classList.add('is-open');
    }
    function closePanel() {
      panel.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      wrapper.classList.remove('is-open');
    }

    trigger.addEventListener('click', function() {
      if (panel.hidden) openPanel();
      else closePanel();
    });

    document.addEventListener('click', function(e) {
      if (!wrapper.contains(e.target)) closePanel();
    });

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') closePanel();
    });

    // select の値が外部要因で変わった時に追従
    selectEl.addEventListener('change', function() {
      var checkboxes = panel.querySelectorAll('input.f616-ms-checkbox');
      Array.prototype.forEach.call(checkboxes, function(cb) {
        var found = Array.prototype.find.call(selectEl.options, function(opt) {
          return opt.value === cb.value;
        });
        if (found) cb.checked = !!found.selected;
      });
      updateTriggerText();
    });

    // DOM 挿入: ラベル直下に表示用UIを置き、元selectは非表示にする
    selectEl.parentNode.insertBefore(wrapper, selectEl);
    wrapper.appendChild(trigger);
    wrapper.appendChild(panel);

    // ラベルクリックでも開く（hidden select にフォーカスが行かないように）
    var labelEl = selectEl.parentNode.querySelector('label[for="' + selectId + '"]');
    if (labelEl) {
      labelEl.addEventListener('click', function(e) {
        e.preventDefault();
        trigger.focus();
        openPanel();
      });
    }

    selectEl.classList.add('f616-native-select-hidden');
    selectEl.setAttribute('aria-hidden', 'true');
    selectEl.tabIndex = -1;

    updateTriggerText();
  }

  // タイムスタンプ設定
  var tsField = document.getElementById('f616_timestamp');
  if (tsField) tsField.value = Date.now().toString();

  /* バリデーション関数 */
  function sanitize(str) {
    return str.replace(/<[^>]*>/g, '').replace(/[<>"']/g, '').trim();
  }

  function validateName() {
    var val = sanitize(document.getElementById('f616_name').value);
    if (!val) return showError('f616_name', 'お名前を入力してください。');
    if (val.length < 2 || val.length > 50) return showError('f616_name', '2〜50文字で入力してください。');
    clearError('f616_name'); return true;
  }
  function validateCompany() {
    var val = sanitize(document.getElementById('f616_company').value);
    if (!val) return showError('f616_company', '会社名を入力してください。');
    if (val.length < 2 || val.length > 100) return showError('f616_company', '2〜100文字で入力してください。');
    clearError('f616_company'); return true;
  }
  function validateEmail() {
    var val = document.getElementById('f616_email').value.trim();
    if (!val) return showError('f616_email', 'メールアドレスを入力してください。');
    var re = /^[a-zA-Z0-9.!#$%&'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/;
    if (!re.test(val)) return showError('f616_email', '正しいメールアドレスを入力してください。');
    var domain = val.split('@')[1].toLowerCase();
    if (DISPOSABLE_DOMAINS.indexOf(domain) !== -1) return showError('f616_email', 'このメールアドレスは使用できません。');
    clearError('f616_email'); return true;
  }
  function validatePhone() {
    var el = document.getElementById('f616_phone');
    if (!el) return true;
    var val = el.value.trim();
    if (!val) { clearError('f616_phone'); return true; }
    if (!/^[0-9\-+()（）\s]{8,20}$/.test(val)) return showError('f616_phone', '正しい電話番号を入力してください。');
    clearError('f616_phone'); return true;
  }
  function validateHomepage() {
    var el = document.getElementById('f616_homepage');
    if (!el) return true;
    var val = el.value.trim();
    if (!val) { clearError('f616_homepage'); return true; }
    try { var u = new URL(val); if (u.protocol !== 'http:' && u.protocol !== 'https:') throw 0; }
    catch(e) { return showError('f616_homepage', '正しいURLを入力してください。'); }
    clearError('f616_homepage'); return true;
  }
  function validateIndustry() {
    var el = document.getElementById('f616_industry');
    if (!el) return true;
    if (!el.value) return showError('f616_industry', '業種を選択してください。');
    clearError('f616_industry'); return true;
  }
  function validateMessage() {
    var el = document.getElementById('f616_message');
    if (!el) return true;
    var val = sanitize(el.value);
    if (!val) return showError('f616_message', 'お問い合わせ内容を入力してください。');
    if (val.length < 10) return showError('f616_message', '10文字以上で入力してください。');
    if (val.length > 2000) return showError('f616_message', '2000文字以内で入力してください。');
    var urls = val.match(/https?:\/\/[^\s]+/g);
    if (urls && urls.length > 3) return showError('f616_message', 'URLは3つまでにしてください。');
    clearError('f616_message'); return true;
  }

  function validatePickup() {
    var el = document.getElementById('f616_pickup');
    if (!el) return true;
    var val = sanitize(el.value);
    if (!val) return showError('f616_pickup', '引取場所を入力してください。');
    if (val.length > 200) return showError('f616_pickup', '200文字以内で入力してください。');
    clearError('f616_pickup'); return true;
  }

  function validateDelivery() {
    var el = document.getElementById('f616_delivery');
    if (!el) return true;
    var selectedCount = 0;
    Array.prototype.forEach.call(el.options, function(opt) {
      if (opt.selected && opt.value) selectedCount++;
    });
    if (selectedCount === 0) return showError('f616_delivery', '納品場所を選択してください。');
    clearError('f616_delivery'); return true;
  }

  function validateItem() {
    var el = document.getElementById('f616_item');
    if (!el) return true;
    var val = sanitize(el.value);
    if (!val) return showError('f616_item', 'お品物の内容を入力してください。');
    if (val.length > 2000) return showError('f616_item', '2000文字以内で入力してください。');
    clearError('f616_item'); return true;
  }
  function validateConsent() {
    if (!consentCb) return true;
    if (!consentCb.checked) return showError('f616_consent', 'プライバシーポリシーに同意してください。');
    clearError('f616_consent'); return true;
  }

  function showError(id, msg) {
    var errEl = document.getElementById(id + '_error');
    var inputEl = document.getElementById(id);
    if (errEl) { errEl.textContent = msg; errEl.classList.add('f616-show'); }
    if (inputEl) inputEl.classList.add('f616-error-field');
    var proxy = form.querySelector('[data-f616-error-proxy="' + id + '"]');
    if (proxy) proxy.classList.add('f616-error-field');
    return false;
  }
  function clearError(id) {
    var errEl = document.getElementById(id + '_error');
    var inputEl = document.getElementById(id);
    if (errEl) { errEl.textContent = ''; errEl.classList.remove('f616-show'); }
    if (inputEl) inputEl.classList.remove('f616-error-field');
    var proxy = form.querySelector('[data-f616-error-proxy="' + id + '"]');
    if (proxy) proxy.classList.remove('f616-error-field');
  }
  function showGlobalError(msg) {
    globalError.textContent = msg;
    globalError.classList.add('f616-show');
    globalError.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  function clearGlobalError() {
    globalError.textContent = '';
    globalError.classList.remove('f616-show');
  }

  /* リアルタイムバリデーション */
  var nameEl = document.getElementById('f616_name');
  if (nameEl) nameEl.addEventListener('blur', validateName);
  var companyEl = document.getElementById('f616_company');
  if (companyEl) companyEl.addEventListener('blur', validateCompany);
  var emailEl = document.getElementById('f616_email');
  if (emailEl) emailEl.addEventListener('blur', validateEmail);

  var pickupEl = document.getElementById('f616_pickup');
  if (pickupEl) pickupEl.addEventListener('blur', validatePickup);
  var deliveryEl = document.getElementById('f616_delivery');
  if (deliveryEl) deliveryEl.addEventListener('change', validateDelivery);
  var itemEl = document.getElementById('f616_item');
  if (itemEl) itemEl.addEventListener('blur', validateItem);

  var phoneEl = document.getElementById('f616_phone');
  if (phoneEl) phoneEl.addEventListener('blur', validatePhone);
  var homepageEl = document.getElementById('f616_homepage');
  if (homepageEl) homepageEl.addEventListener('blur', validateHomepage);
  var industryEl = document.getElementById('f616_industry');
  if (industryEl) industryEl.addEventListener('change', validateIndustry);
  var messageEl = document.getElementById('f616_message');
  if (messageEl) messageEl.addEventListener('blur', validateMessage);

  if (messageEl) {
    messageEl.addEventListener('input', function() {
      var countEl = document.getElementById('f616_message_count');
      if (!countEl) return;
      var c = this.value.length;
      countEl.textContent = c + ' / 2000';
      if (c > 2000) countEl.classList.add('f616-over'); else countEl.classList.remove('f616-over');
    });
  }

  if (consentCb && submitBtn) {
    consentCb.addEventListener('change', function() {
      submitBtn.disabled = !this.checked;
    });
  }

  // 納品場所 UI 初期化
  setupMultiSelectFromNative('f616_delivery', '選択してください');

  /* フォーム送信 */
  form.addEventListener('submit', async function(e) {
    e.preventDefault();
    clearGlobalError();

    var results = [
      validateName(), validateCompany(), validateEmail(),
      validatePickup(), validateDelivery(), validateItem(),
      validatePhone(), validateHomepage(), validateIndustry(),
      validateMessage(), validateConsent()
    ];

    if (results.indexOf(false) !== -1) {
      var first = form.querySelector('.f616-error-field');
      if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }

    var ts = parseInt(tsField.value, 10);
    var elapsed = (Date.now() - ts) / 1000;
    if (elapsed < CONFIG.MIN_SUBMIT_TIME) {
      showGlobalError('送信が早すぎます。内容をご確認の上、もう一度お試しください。');
      return;
    }
    if (elapsed > CONFIG.MAX_SUBMIT_TIME) {
      showGlobalError('セッションの有効期限が切れました。ページを更新してください。');
      return;
    }

    if (document.getElementById('f616_website').value || document.getElementById('f616_url_confirm').value) {
      showComplete('INQ-0000');
      return;
    }

    submitBtn.classList.add('f616-loading');
    submitBtn.disabled = true;

    try {
      var recaptchaToken = await grecaptcha.execute(CONFIG.RECAPTCHA_SITE_KEY, { action: 'contact_submit' });
      document.getElementById('f616_recaptcha_token').value = recaptchaToken;
    } catch(err) {
      showGlobalError('reCAPTCHA の検証に失敗しました。ページを更新してお試しください。');
      submitBtn.classList.remove('f616-loading');
      submitBtn.disabled = false;
      return;
    }

    var formData = new FormData(form);

    try {
      var response = await fetch(CONFIG.PHP_ENDPOINT, { method: 'POST', body: formData });
      var result = await response.json();

      if (result.success) {
        showComplete(result.inquiry_number || 'INQ-0000');
      } else {
        showGlobalError(result.message || '送信に失敗しました。時間を置いて再度お試しください。');
        submitBtn.classList.remove('f616-loading');
        submitBtn.disabled = false;
      }
    } catch(err) {
      showGlobalError('通信エラーが発生しました。ネットワーク接続を確認してください。');
      submitBtn.classList.remove('f616-loading');
      submitBtn.disabled = false;
    }
  });

  function showComplete(number) {
    window.location.href = 'https://kashiwa616.com/lp/contact/thanks/';
  }

  /* FAQ アコーディオン */
  document.querySelectorAll('.faq-question').forEach(function(q) {
    q.addEventListener('click', function() {
      var answer = this.nextElementSibling;
      var isOpen = answer.style.maxHeight && answer.style.maxHeight !== '0px';
      document.querySelectorAll('.faq-answer').forEach(function(a) {
        a.style.maxHeight = '0px';
        a.style.paddingTop = '0';
        a.style.paddingBottom = '0';
      });
      if (!isOpen) {
        answer.style.maxHeight = answer.scrollHeight + 'px';
        answer.style.paddingTop = '15px';
        answer.style.paddingBottom = '15px';
      }
    });
  });

  /* CTA セクションボタン→フォームへスクロール */
  var faqCtaBtn = document.querySelector('.faq-cta-btn');
  if (faqCtaBtn) {
    faqCtaBtn.addEventListener('click', function() {
      var contactEl = document.getElementById('contact');
      if (contactEl) contactEl.scrollIntoView({ behavior: 'smooth' });
    });
  }

})();