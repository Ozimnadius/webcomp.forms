<?php

use Bitrix\Main\Page\Asset;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

/** @var array $arResult */

Asset::getInstance()->addCss('https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css');

$view = $arResult['VIEW'];
?>

<?php if ($view['MODE'] === 'popup_shell'): ?>
    <?php
    $popup = $view['POPUP'];
    Asset::getInstance()->addCss('/bitrix/css/webcomp.forms/dialog.css');
    Asset::getInstance()->addJs('/bitrix/js/webcomp.forms/dialog.js');
    ?>
    <div class="webcomp-form-popup">
        <?php if ($view['SHOW_SUCCESS']): ?>
            <div class="alert alert-success" role="alert">
                Форма успешно отправлена.
            </div>
        <?php endif; ?>

        <button
            type="button"
            class="<?= htmlspecialcharsbx($popup['BUTTON_CLASS']) ?>"
            data-webcomp-dialog-url="<?= htmlspecialcharsbx($popup['FRAGMENT_URL']) ?>"
            data-webcomp-dialog-key="<?= htmlspecialcharsbx($popup['DIALOG_KEY']) ?>"
        ><?= htmlspecialcharsbx($popup['BUTTON_TEXT']) ?></button>
    </div>
    <?php return; ?>
<?php endif; ?>

<div class="webcomp-form">
    <?php if ($view['SHOW_SUCCESS']): ?>
        <div class="alert alert-success" role="alert">
            Форма успешно отправлена.
        </div>
    <?php elseif ($view['SHOW_ERROR_ALERT']): ?>
        <div class="alert alert-danger" role="alert">
            Проверьте правильность заполнения формы.
        </div>
    <?php endif; ?>

    <?php if ($view['SYSTEM_ERROR'] !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <?= htmlspecialcharsbx($view['SYSTEM_ERROR']) ?>
        </div>
    <?php endif; ?>

    <div class="webcomp-form__ajax-message" data-webcomp-form-message hidden></div>

    <form
        id="<?= htmlspecialcharsbx($view['FORM_HTML_ID']) ?>"
        class="webcomp-form__form"
        method="post"
        action="<?= htmlspecialcharsbx($view['FORM_ACTION']) ?>"
        enctype="multipart/form-data"
        data-webcomp-form
        data-ajax="<?= htmlspecialcharsbx((string)($arResult['USE_AJAX'] ?? 'N')) ?>"
        data-success-message="Форма успешно отправлена."
        data-error-message="Проверьте правильность заполнения формы."
    >
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="webcomp_form_submit" value="Y">
        <input type="hidden" name="PARAMS_HASH" value="<?= htmlspecialcharsbx((string)($arResult['PARAMS_HASH'] ?? '')) ?>">

        <?php foreach ($view['FIELDS'] as $field): ?>
            <?php $hasError = $field['ERROR'] !== ''; ?>
            <div class="mb-3">
                <?php if (in_array($field['CONTROL'], ['input', 'textarea', 'select'], true)): ?>
                    <label for="<?= htmlspecialcharsbx($field['HTML_ID']) ?>" class="form-label"><?= htmlspecialcharsbx($field['LABEL']) ?><?php if ($field['REQUIRED']): ?> <span class="text-danger">*</span><?php endif; ?></label>
                <?php endif; ?>

                <?php if ($field['CONTROL'] === 'input'): ?>
                    <input
                        type="<?= htmlspecialcharsbx($field['INPUT_TYPE']) ?>"
                        class="form-control<?= $hasError ? ' is-invalid' : '' ?>"
                        id="<?= htmlspecialcharsbx($field['HTML_ID']) ?>"
                        name="<?= htmlspecialcharsbx($field['INPUT_NAME']) ?>"
                        value="<?= htmlspecialcharsbx($field['VALUE']) ?>"
                        placeholder="<?= htmlspecialcharsbx($field['PLACEHOLDER']) ?>"
                        <?= $field['REQUIRED'] ? 'required' : '' ?>
                    >
                    <?php if ($hasError): ?><div class="invalid-feedback"><?= htmlspecialcharsbx($field['ERROR']) ?></div><?php endif; ?>
                <?php elseif ($field['CONTROL'] === 'textarea'): ?>
                    <textarea
                        class="form-control<?= $hasError ? ' is-invalid' : '' ?>"
                        id="<?= htmlspecialcharsbx($field['HTML_ID']) ?>"
                        name="<?= htmlspecialcharsbx($field['INPUT_NAME']) ?>"
                        rows="4"
                        placeholder="<?= htmlspecialcharsbx($field['PLACEHOLDER']) ?>"
                        <?= $field['REQUIRED'] ? 'required' : '' ?>
                    ><?= htmlspecialcharsbx($field['VALUE']) ?></textarea>
                    <?php if ($hasError): ?><div class="invalid-feedback"><?= htmlspecialcharsbx($field['ERROR']) ?></div><?php endif; ?>
                <?php elseif ($field['CONTROL'] === 'select'): ?>
                    <select
                        class="form-select<?= $hasError ? ' is-invalid' : '' ?>"
                        id="<?= htmlspecialcharsbx($field['HTML_ID']) ?>"
                        name="<?= htmlspecialcharsbx($field['INPUT_NAME']) ?>"
                        <?= $field['IS_MULTIPLE_SELECT'] ? 'multiple' : '' ?>
                        <?= $field['REQUIRED'] ? 'required' : '' ?>
                    >
                        <?php if (!$field['IS_MULTIPLE_SELECT']): ?>
                            <option value=""><?= htmlspecialcharsbx($field['PLACEHOLDER'] !== '' ? $field['PLACEHOLDER'] : 'Выберите значение') ?></option>
                        <?php endif; ?>
                        <?php foreach ($field['OPTIONS'] as $option): ?>
                            <option value="<?= htmlspecialcharsbx($option['VALUE']) ?>" <?= $option['SELECTED'] ? 'selected' : '' ?>>
                                <?= htmlspecialcharsbx($option['TEXT']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($hasError): ?><div class="invalid-feedback"><?= htmlspecialcharsbx($field['ERROR']) ?></div><?php endif; ?>
                <?php elseif ($field['CONTROL'] === 'radio' || $field['CONTROL'] === 'checkbox_list'): ?>
                    <fieldset>
                        <legend class="form-label fs-6"><?= htmlspecialcharsbx($field['LABEL']) ?><?php if ($field['REQUIRED']): ?> <span class="text-danger">*</span><?php endif; ?></legend>
                        <?php foreach ($field['OPTIONS'] as $option): ?>
                            <div class="form-check">
                                <input
                                    class="form-check-input<?= $hasError ? ' is-invalid' : '' ?>"
                                    type="<?= $field['CONTROL'] === 'radio' ? 'radio' : 'checkbox' ?>"
                                    id="<?= htmlspecialcharsbx($option['HTML_ID']) ?>"
                                    name="<?= htmlspecialcharsbx($field['INPUT_NAME']) ?>"
                                    value="<?= htmlspecialcharsbx($option['VALUE']) ?>"
                                    <?= $option['SELECTED'] ? 'checked' : '' ?>
                                    <?= $field['REQUIRED'] ? ($field['CONTROL'] === 'radio' ? 'required' : 'aria-required="true"') : '' ?>
                                >
                                <label class="form-check-label" for="<?= htmlspecialcharsbx($option['HTML_ID']) ?>">
                                    <?= htmlspecialcharsbx($option['TEXT']) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($hasError): ?><div class="invalid-feedback d-block"><?= htmlspecialcharsbx($field['ERROR']) ?></div><?php endif; ?>
                    </fieldset>
                <?php elseif ($field['CONTROL'] === 'checkbox_single'): ?>
                    <div class="form-check">
                        <input
                            class="form-check-input<?= $hasError ? ' is-invalid' : '' ?>"
                            type="checkbox"
                            id="<?= htmlspecialcharsbx($field['HTML_ID']) ?>"
                            name="<?= htmlspecialcharsbx($field['INPUT_NAME']) ?>"
                            value="Y"
                            <?= $field['IS_CHECKED'] ? 'checked' : '' ?>
                            <?= $field['REQUIRED'] ? 'required' : '' ?>
                        >
                        <label class="form-check-label" for="<?= htmlspecialcharsbx($field['HTML_ID']) ?>">
                            <?= htmlspecialcharsbx($field['LABEL']) ?><?php if ($field['REQUIRED']): ?> <span class="text-danger">*</span><?php endif; ?>
                        </label>
                        <?php if ($hasError): ?><div class="invalid-feedback"><?= htmlspecialcharsbx($field['ERROR']) ?></div><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <button type="submit" class="btn btn-primary" data-webcomp-form-submit>
            <?= htmlspecialcharsbx((string)($arResult['SUBMIT_TEXT'] ?? 'Отправить')) ?>
        </button>
    </form>
</div>
