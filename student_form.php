<?php
declare(strict_types=1);

/**
 * student_form.php - the form used by add_student.php and update_student.php,
 * including the JavaScript for real-time validation (no page reload).
 */

require_once __DIR__ . '/student_helpers.php';
require_once __DIR__ . '/layout.php';

/**
 * @param string     $action      form action URL
 * @param array      $values      name/email/phone/address currently shown
 * @param array      $errors      server-side errors [field => message]
 * @param string     $submitLabel text of the submit button
 * @param string     $cancelUrl   where "إلغاء" goes
 * @param array|null $student     the DB row when editing (id + profile_picture)
 */
function render_student_form(
    string $action,
    array $values,
    array $errors,
    string $submitLabel,
    string $cancelUrl,
    ?array $student = null
): void {
    $studentId  = isset($student['id']) ? (int) $student['id'] : 0;
    $pictureUrl = profile_picture_url($student['profile_picture'] ?? null);
    $srcAttr    = $pictureUrl !== null ? 'src="' . e($pictureUrl) . '"' : '';
    $maxMb      = (int) (MAX_UPLOAD_BYTES / 1048576);
    ?>
<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data"
      id="student-form" novalidate data-student-id="<?= $studentId > 0 ? $studentId : '' ?>">
    <?= csrf_field() ?>

    <div class="row g-3">

        <div class="col-12 col-md-6">
            <label for="name" class="form-label fw-semibold">الاسم الكامل <span class="text-danger">*</span></label>
            <input type="text" class="form-control<?= is_invalid($errors, 'name') ?>"
                   id="name" name="name" value="<?= e($values['name'] ?? '') ?>"
                   maxlength="100" autocomplete="off" required>
            <div class="invalid-feedback" id="name-feedback"><?= e($errors['name'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
            <label for="email" class="form-label fw-semibold">البريد الإلكتروني <span class="text-danger">*</span></label>
            <input type="email" dir="ltr" class="form-control<?= is_invalid($errors, 'email') ?>"
                   id="email" name="email" value="<?= e($values['email'] ?? '') ?>"
                   placeholder="name@example.com" maxlength="255" autocomplete="off" required>
            <div class="invalid-feedback" id="email-feedback"><?= e($errors['email'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
            <label for="phone" class="form-label fw-semibold">رقم الهاتف <span class="text-danger">*</span></label>
            <input type="tel" dir="ltr" class="form-control<?= is_invalid($errors, 'phone') ?>"
                   id="phone" name="phone" value="<?= e($values['phone'] ?? '') ?>"
                   maxlength="20" inputmode="tel" autocomplete="off" required>
            <div class="invalid-feedback" id="phone-feedback"><?= e($errors['phone'] ?? '') ?></div>
            <div class="form-text">يمكن أن يبدأ الرقم بالرمز (+)، وتُقبل الأرقام العربية والإنجليزية.</div>
        </div>

        <div class="col-12">
            <label for="address" class="form-label fw-semibold">العنوان <span class="text-muted fw-normal">(اختياري)</span></label>
            <textarea class="form-control<?= is_invalid($errors, 'address') ?>"
                      id="address" name="address" rows="3" maxlength="500"><?= e($values['address'] ?? '') ?></textarea>
            <div class="invalid-feedback" id="address-feedback"><?= e($errors['address'] ?? '') ?></div>
        </div>

        <div class="col-12">
            <label for="profile_picture" class="form-label fw-semibold">الصورة الشخصية <span class="text-muted fw-normal">(اختياري)</span></label>
            <div class="d-flex align-items-center gap-3">
                <span class="avatar avatar-lg">
                    <img id="picture-preview" <?= $srcAttr ?> data-original="<?= e($pictureUrl ?? '') ?>"
                         alt="معاينة الصورة الشخصية" class="<?= $pictureUrl === null ? 'd-none' : '' ?>">
                    <i id="picture-placeholder" class="bi bi-person-fill<?= $pictureUrl === null ? '' : ' d-none' ?>" aria-hidden="true"></i>
                </span>
                <div class="flex-grow-1">
                    <input type="file" class="form-control<?= is_invalid($errors, 'profile_picture') ?>"
                           id="profile_picture" name="profile_picture"
                           accept="image/jpeg,image/png,image/webp">
                    <div class="invalid-feedback" id="profile_picture-feedback"><?= e($errors['profile_picture'] ?? '') ?></div>
                    <div class="form-text">
                        الصيغ المسموحة: JPG وPNG وWEBP، بحد أقصى <?= $maxMb ?> ميغابايت.
                        <?= $student !== null ? 'اتركه فارغًا للاحتفاظ بالصورة الحالية.' : '' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-4">
        <button type="submit" class="btn btn-primary px-4 fw-semibold" id="submit-btn">
            <i class="bi bi-check-lg me-1"></i><?= e($submitLabel) ?>
        </button>
        <a href="<?= e($cancelUrl) ?>" class="btn btn-outline-secondary px-4">إلغاء</a>
    </div>
</form>

<script>
(function () {
    var form = document.getElementById('student-form');
    if (!form || !window.fetch) { return; }   // without JS the server still validates everything

    var ENDPOINT    = 'validate_student.php';
    var MAX_BYTES   = <?= (int) MAX_UPLOAD_BYTES ?>;
    var ALLOWED     = ['image/jpeg', 'image/png', 'image/webp'];
    var csrf        = form.querySelector('input[name="csrf_token"]').value;
    var studentId   = form.getAttribute('data-student-id') || '';
    var submitBtn   = document.getElementById('submit-btn');
    var submitHtml  = submitBtn.innerHTML;
    var inputs      = ['name', 'email', 'phone', 'address'].map(function (id) { return document.getElementById(id); });
    var picture     = document.getElementById('profile_picture');
    var previewImg  = document.getElementById('picture-preview');
    var placeholder = document.getElementById('picture-placeholder');
    var originalSrc = previewImg.getAttribute('data-original') || '';
    var pending = {}, timers = {}, touched = {};

    // ---- visual state of a field ----
    function setState(input, ok, message) {
        var feedback = document.getElementById(input.id + '-feedback');
        input.classList.remove('is-valid', 'is-invalid');
        if (ok === true)  { input.classList.add('is-valid'); }
        if (ok === false) { input.classList.add('is-invalid'); }
        if (feedback) { feedback.textContent = (ok === false) ? (message || '') : ''; }
    }

    // ---- ask the server about one field (resolves true / false / null=unknown) ----
    function validateField(input) {
        var key = input.name;

        if (key === 'address' && input.value.trim() === '') {   // optional + empty
            setState(input, null);
            return Promise.resolve(true);
        }

        if (pending[key]) { pending[key].abort(); }
        var controller = ('AbortController' in window) ? new AbortController() : null;
        pending[key] = controller;

        var body = new URLSearchParams();
        body.append('field', key);
        body.append('value', input.value);
        body.append('student_id', studentId);
        body.append('csrf_token', csrf);

        var options = { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (controller) { options.signal = controller.signal; }

        return fetch(ENDPOINT, options)
            .then(function (response) {
                if (response.status >= 500) { throw new Error('server'); }
                return response.json();
            })
            .then(function (data) {
                setState(input, data.valid === true, data.message);
                return data.valid === true;
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') { return null; }
                setState(input, null);
                return null;   // unknown: the server validates again on submit
            });
    }

    inputs.forEach(function (input) {
        if (input.classList.contains('is-invalid')) { touched[input.id] = true; }

        input.addEventListener('blur', function () {
            touched[input.id] = true;
            validateField(input);
        });
        input.addEventListener('input', function () {
            if (!touched[input.id]) { return; }
            clearTimeout(timers[input.id]);
            timers[input.id] = setTimeout(function () { validateField(input); }, 400);
        });
    });

    // ---- profile picture: instant client-side check + preview ----
    function showPreview(src) {
        previewImg.src = src;
        previewImg.classList.remove('d-none');
        placeholder.classList.add('d-none');
    }
    function resetPreview() {
        if (originalSrc) {
            showPreview(originalSrc);
        } else {
            previewImg.removeAttribute('src');
            previewImg.classList.add('d-none');
            placeholder.classList.remove('d-none');
        }
    }
    function rejectPicture(message) {
        picture.value = '';
        resetPreview();
        setState(picture, false, message);
    }

    picture.addEventListener('change', function () {
        var file = picture.files && picture.files[0];
        if (!file) { resetPreview(); setState(picture, null); return; }
        if (ALLOWED.indexOf(file.type) === -1) {
            rejectPicture('نوع الملف غير مسموح. الصيغ المسموحة: JPG وPNG وWEBP.');
            return;
        }
        if (file.size > MAX_BYTES) {
            rejectPicture('حجم الصورة يتجاوز الحد المسموح به (<?= $maxMb ?> ميغابايت).');
            return;
        }
        showPreview(URL.createObjectURL(file));
        setState(picture, true);
    });

    // ---- on submit: validate everything with the server first ----
    form.addEventListener('submit', function (event) {
        if (form.getAttribute('data-ready') === '1') { return; }   // validated: let it post
        event.preventDefault();

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>جارٍ التحقق...';

        Promise.all(inputs.map(validateField)).then(function (results) {
            var firstInvalid = null;
            results.forEach(function (ok, index) {
                if (ok === false && firstInvalid === null) { firstInvalid = inputs[index]; }
            });
            if (firstInvalid === null && picture.classList.contains('is-invalid')) { firstInvalid = picture; }

            if (firstInvalid !== null) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = submitHtml;
                firstInvalid.focus();
                return;
            }

            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>جارٍ الحفظ...';
            form.setAttribute('data-ready', '1');
            form.submit();
        });
    });
})();
</script>
<?php
}
