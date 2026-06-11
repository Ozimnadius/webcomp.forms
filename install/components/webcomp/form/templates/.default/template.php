<?php

use Bitrix\Main\Page\Asset;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Asset::getInstance()->addCss('https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css');

$form = is_array($arResult['FORM'] ?? null) ? $arResult['FORM'] : [];
$fields = is_array($arResult['FIELDS'] ?? null) ? $arResult['FIELDS'] : [];
$values = is_array($arResult['VALUES'] ?? null) ? $arResult['VALUES'] : [];
$errors = is_array($arResult['ERRORS'] ?? null) ? $arResult['ERRORS'] : [];
$isSubmitted = (bool)($arResult['IS_SUBMITTED'] ?? false);
$isSuccess = (bool)($arResult['SHOW_SUCCESS'] ?? false);
$formId = 'webcomp-form-' . (int)($form['ID'] ?? 0);

$getFieldValue = static function (array $field) use ($values) {
    $code = (string)($field['CODE'] ?? '');

    return $values[$code] ?? '';
};

$getValueList = static function ($value): array {
    $list = is_array($value) ? $value : [$value];

    return array_values(array_filter(array_map(
        static fn($item): string => (string)$item,
        $list
    ), static fn(string $item): bool => $item !== ''));
};

$getFieldId = static function (string $formId, array $field, string $suffix = ''): string {
    $code = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string)($field['CODE'] ?? 'field'));
    $fieldId = $formId . '-' . trim((string)$code, '-');

    if ($suffix !== '') {
        $fieldId .= '-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $suffix);
    }

    return $fieldId;
};

$getOptionText = static function (array $option): string {
    $text = trim((string)($option['TEXT'] ?? ''));

    return $text !== '' ? $text : (string)($option['VALUE'] ?? '');
};
?>

<div class="webcomp-form">
    <?php if ($isSuccess): ?>
        <div class="alert alert-success" role="alert">
            Форма успешно отправлена.
        </div>
    <?php elseif ($isSubmitted && $errors !== []): ?>
        <div class="alert alert-danger" role="alert">
            Проверьте правильность заполнения формы.
        </div>
    <?php endif; ?>

    <?php if (isset($errors['SYSTEM'])): ?>
        <div class="alert alert-danger" role="alert">
            <?= htmlspecialcharsbx((string)$errors['SYSTEM']) ?>
        </div>
    <?php endif; ?>

    <div class="webcomp-form__ajax-message" data-webcomp-form-message hidden></div>

    <form
        id="<?= htmlspecialcharsbx($formId) ?>"
        class="webcomp-form__form"
        method="post"
        action="<?= htmlspecialcharsbx(POST_FORM_ACTION_URI) ?>"
        enctype="multipart/form-data"
        data-webcomp-form
        data-ajax="<?= htmlspecialcharsbx((string)($arResult['USE_AJAX'] ?? 'N')) ?>"
        data-success-message="Форма успешно отправлена."
        data-error-message="Проверьте правильность заполнения формы."
    >
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="webcomp_form_submit" value="Y">
        <input type="hidden" name="PARAMS_HASH" value="<?= htmlspecialcharsbx((string)($arResult['PARAMS_HASH'] ?? '')) ?>">

        <?php foreach ($fields as $field): ?>
            <?php
            $code = (string)($field['CODE'] ?? '');

            if ($code === '') {
                continue;
            }

            $type = (string)($field['TYPE'] ?? 'text');
            $label = (string)($field['LABEL'] ?? $field['NAME'] ?? $code);
            $placeholder = (string)($field['PLACEHOLDER'] ?? '');
            $required = (bool)($field['REQUIRED'] ?? false);
            $options = is_array($field['OPTIONS'] ?? null) ? $field['OPTIONS'] : [];
            $fieldValue = $getFieldValue($field);
            $fieldValues = $getValueList($fieldValue);
            $fieldId = $getFieldId($formId, $field);
            $fieldError = (string)($errors[$code] ?? '');
            $hasError = $fieldError !== '';
            $isMultipleSelect = $type === 'select' && (($field['MULTIPLE'] ?? false) === true);
            $inputName = $isMultipleSelect ? $code . '[]' : $code;
            ?>

            <div class="mb-3">
                <?php if (in_array($type, ['text', 'email', 'tel', 'url', 'number', 'date'], true)): ?>
                    <label for="<?= htmlspecialcharsbx($fieldId) ?>" class="form-label">
                        <?= htmlspecialcharsbx($label) ?>
                        <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                    </label>
                    <input
                        type="<?= htmlspecialcharsbx($type) ?>"
                        class="form-control<?= $hasError ? ' is-invalid' : '' ?>"
                        id="<?= htmlspecialcharsbx($fieldId) ?>"
                        name="<?= htmlspecialcharsbx($code) ?>"
                        value="<?= htmlspecialcharsbx((string)$fieldValue) ?>"
                        placeholder="<?= htmlspecialcharsbx($placeholder) ?>"
                        <?= $required ? 'required' : '' ?>
                    >
                    <?php if ($hasError): ?>
                        <div class="invalid-feedback">
                            <?= htmlspecialcharsbx($fieldError) ?>
                        </div>
                    <?php endif; ?>
                <?php elseif ($type === 'textarea'): ?>
                    <label for="<?= htmlspecialcharsbx($fieldId) ?>" class="form-label">
                        <?= htmlspecialcharsbx($label) ?>
                        <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                    </label>
                    <textarea
                        class="form-control<?= $hasError ? ' is-invalid' : '' ?>"
                        id="<?= htmlspecialcharsbx($fieldId) ?>"
                        name="<?= htmlspecialcharsbx($code) ?>"
                        rows="4"
                        placeholder="<?= htmlspecialcharsbx($placeholder) ?>"
                        <?= $required ? 'required' : '' ?>
                    ><?= htmlspecialcharsbx((string)$fieldValue) ?></textarea>
                    <?php if ($hasError): ?>
                        <div class="invalid-feedback">
                            <?= htmlspecialcharsbx($fieldError) ?>
                        </div>
                    <?php endif; ?>
                <?php elseif ($type === 'select'): ?>
                    <label for="<?= htmlspecialcharsbx($fieldId) ?>" class="form-label">
                        <?= htmlspecialcharsbx($label) ?>
                        <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                    </label>
                    <select
                        class="form-select<?= $hasError ? ' is-invalid' : '' ?>"
                        id="<?= htmlspecialcharsbx($fieldId) ?>"
                        name="<?= htmlspecialcharsbx($inputName) ?>"
                        <?= $isMultipleSelect ? 'multiple' : '' ?>
                        <?= $required ? 'required' : '' ?>
                    >
                        <?php if (!$isMultipleSelect): ?>
                            <option value=""><?= htmlspecialcharsbx($placeholder !== '' ? $placeholder : 'Выберите значение') ?></option>
                        <?php endif; ?>
                        <?php foreach ($options as $option): ?>
                            <?php
                            $optionValue = (string)($option['VALUE'] ?? '');

                            if ($optionValue === '') {
                                continue;
                            }
                            ?>
                            <option value="<?= htmlspecialcharsbx($optionValue) ?>" <?= in_array($optionValue, $fieldValues, true) ? 'selected' : '' ?>>
                                <?= htmlspecialcharsbx($getOptionText($option)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($hasError): ?>
                        <div class="invalid-feedback">
                            <?= htmlspecialcharsbx($fieldError) ?>
                        </div>
                    <?php endif; ?>
                <?php elseif ($type === 'radio'): ?>
                    <fieldset>
                        <legend class="form-label fs-6">
                            <?= htmlspecialcharsbx($label) ?>
                            <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                        </legend>
                        <?php foreach ($options as $optionIndex => $option): ?>
                            <?php
                            $optionValue = (string)($option['VALUE'] ?? '');

                            if ($optionValue === '') {
                                continue;
                            }

                            $optionId = $getFieldId($formId, $field, (string)$optionIndex);
                            ?>
                            <div class="form-check">
                                <input
                                    class="form-check-input<?= $hasError ? ' is-invalid' : '' ?>"
                                    type="radio"
                                    id="<?= htmlspecialcharsbx($optionId) ?>"
                                    name="<?= htmlspecialcharsbx($code) ?>"
                                    value="<?= htmlspecialcharsbx($optionValue) ?>"
                                    <?= in_array($optionValue, $fieldValues, true) ? 'checked' : '' ?>
                                    <?= $required ? 'required' : '' ?>
                                >
                                <label class="form-check-label" for="<?= htmlspecialcharsbx($optionId) ?>">
                                    <?= htmlspecialcharsbx($getOptionText($option)) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($hasError): ?>
                            <div class="invalid-feedback d-block">
                                <?= htmlspecialcharsbx($fieldError) ?>
                            </div>
                        <?php endif; ?>
                    </fieldset>
                <?php elseif ($type === 'checkbox' && $options !== []): ?>
                    <fieldset>
                        <legend class="form-label fs-6">
                            <?= htmlspecialcharsbx($label) ?>
                            <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                        </legend>
                        <?php foreach ($options as $optionIndex => $option): ?>
                            <?php
                            $optionValue = (string)($option['VALUE'] ?? '');

                            if ($optionValue === '') {
                                continue;
                            }

                            $optionId = $getFieldId($formId, $field, (string)$optionIndex);
                            ?>
                            <div class="form-check">
                                <input
                                    class="form-check-input<?= $hasError ? ' is-invalid' : '' ?>"
                                    type="checkbox"
                                    id="<?= htmlspecialcharsbx($optionId) ?>"
                                    name="<?= htmlspecialcharsbx($code) ?>[]"
                                    value="<?= htmlspecialcharsbx($optionValue) ?>"
                                    <?= in_array($optionValue, $fieldValues, true) ? 'checked' : '' ?>
                                    <?= $required ? 'aria-required="true"' : '' ?>
                                >
                                <label class="form-check-label" for="<?= htmlspecialcharsbx($optionId) ?>">
                                    <?= htmlspecialcharsbx($getOptionText($option)) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($hasError): ?>
                            <div class="invalid-feedback d-block">
                                <?= htmlspecialcharsbx($fieldError) ?>
                            </div>
                        <?php endif; ?>
                    </fieldset>
                <?php elseif ($type === 'checkbox'): ?>
                    <div class="form-check">
                        <input
                            class="form-check-input<?= $hasError ? ' is-invalid' : '' ?>"
                            type="checkbox"
                            id="<?= htmlspecialcharsbx($fieldId) ?>"
                            name="<?= htmlspecialcharsbx($code) ?>"
                            value="Y"
                            <?= (string)$fieldValue === 'Y' ? 'checked' : '' ?>
                            <?= $required ? 'required' : '' ?>
                        >
                        <label class="form-check-label" for="<?= htmlspecialcharsbx($fieldId) ?>">
                            <?= htmlspecialcharsbx($label) ?>
                            <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                        </label>
                        <?php if ($hasError): ?>
                            <div class="invalid-feedback">
                                <?= htmlspecialcharsbx($fieldError) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <label for="<?= htmlspecialcharsbx($fieldId) ?>" class="form-label">
                        <?= htmlspecialcharsbx($label) ?>
                        <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                    </label>
                    <input
                        type="text"
                        class="form-control<?= $hasError ? ' is-invalid' : '' ?>"
                        id="<?= htmlspecialcharsbx($fieldId) ?>"
                        name="<?= htmlspecialcharsbx($code) ?>"
                        value="<?= htmlspecialcharsbx((string)$fieldValue) ?>"
                        placeholder="<?= htmlspecialcharsbx($placeholder) ?>"
                        <?= $required ? 'required' : '' ?>
                    >
                    <?php if ($hasError): ?>
                        <div class="invalid-feedback">
                            <?= htmlspecialcharsbx($fieldError) ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <button type="submit" class="btn btn-primary" data-webcomp-form-submit>
            <?= htmlspecialcharsbx((string)($arResult['SUBMIT_TEXT'] ?? 'Отправить')) ?>
        </button>
    </form>
</div>

<script src="<?= htmlspecialcharsbx(CUtil::GetAdditionalFileURL($templateFolder . '/script.js')) ?>"></script>
