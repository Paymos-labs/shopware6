<?php

declare(strict_types=1);

// BUG-189: what the buyer sees on Shopware's edit-order page when the handler
// interrupted the payment with the Paymos review code. The page is stock
// @Storefront/storefront/page/account/order/index.html.twig: every non-null
// error code renders account.externalPaymentFailure ("change the payment
// method or try again") above a "Complete payment" heading, the payment-method
// picker and the submit button (Shopware 6.5.8, 6.6.10, 6.7.9 — same blocks).

function sw_review_template_path()
{
    return PAYMOS_SW_SRC_DIR . 'Resources/views/storefront/page/account/order/index.html.twig';
}

/**
 * @return array<string, string> block name => body
 */
function sw_twig_blocks($twig)
{
    preg_match_all('/\{%\s*block\s+(\w+)\s*%\}(.*?)\{%\s*endblock\s*%\}/s', $twig, $matches, PREG_SET_ORDER);
    $blocks = array();
    foreach ($matches as $match) {
        $blocks[$match[1]] = $match[2];
    }

    return $blocks;
}

/**
 * Shopware reads plugin snippets from Resources/snippet/**, named
 * <name>.<locale>.json (SnippetFileLoader::loadSnippetFilesInDir).
 *
 * @return array<string, string> locale => path
 */
function sw_storefront_snippet_files()
{
    $files = array();
    foreach (array('en-GB', 'de-DE', 'ru-RU', 'es-ES', 'tr-TR', 'zh-CN') as $locale) {
        $files[$locale] = PAYMOS_SW_SRC_DIR . 'Resources/snippet/' . str_replace('-', '_', $locale) . '/paymos.' . $locale . '.json';
    }

    return $files;
}

function sw_snippet_value(array $tree, $key)
{
    $node = $tree;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($node) || !array_key_exists($segment, $node)) {
            return null;
        }
        $node = $node[$segment];
    }

    return is_string($node) ? $node : null;
}

function test_sw_storefront_review_template_extends_the_stock_edit_order_page()
{
    $path = sw_review_template_path();
    assertTrueValue(is_file($path), 'the plugin overrides the edit-order page template.');

    $twig = (string) file_get_contents($path);
    assertSameValue(1, preg_match('/^\{%\s*sw_extends\s+\'@Storefront\/storefront\/page\/account\/order\/index\.html\.twig\'\s*%\}/', ltrim(preg_replace('/^\{#.*?#\}/s', '', ltrim($twig)))), 'the template extends the stock page instead of replacing it.');
    assertContainsValue("'" . SW_REVIEW_ERROR_CODE . "'", $twig, 'the template answers the code the handler throws.');
}

function test_sw_storefront_review_template_offers_no_way_to_pay_again()
{
    $blocks = sw_twig_blocks((string) file_get_contents(sw_review_template_path()));

    foreach (array(
        'page_checkout_confirm_header',
        'page_checkout_confirm_payment',
        'page_checkout_confirm_tos_control',
        'page_checkout_confirm_revocation_control',
        'page_checkout_confirm_form_submit',
    ) as $name) {
        assertTrueValue(isset($blocks[$name]), $name . ' is overridden.');
        $body = $blocks[$name];
        assertContainsValue(SW_REVIEW_ERROR_CODE, $body, $name . ': only the review code changes it.');
        assertContainsValue('parent()', $body, $name . ': every other code keeps the stock page.');

        // The review branch is everything before the {% else %} that hands
        // back to the stock block.
        $parts = preg_split('/\{%\s*else\s*%\}/', $body, 2);
        assertSameValue(2, count($parts), $name . ': a review branch and a stock branch.');
        $review = $parts[0];
        assertFalseValue(strpos($review, 'parent()') !== false, $name . ': the review branch does not fall back to the stock block.');
        foreach (array('account.externalPaymentFailure', 'account.completePayment', 'account.editOrderUpdateButton', 'confirm-payment') as $stock) {
            assertFalseValue(strpos($review, $stock) !== false, $name . ': the review branch shows no "' . $stock . '".');
        }
    }

    $header = preg_split('/\{%\s*else\s*%\}/', $blocks['page_checkout_confirm_header'], 2)[0];
    assertContainsValue("'paymosPayments.reviewRequired.heading'|trans", $header, 'the header shows the review heading.');
    assertContainsValue("'paymosPayments.reviewRequired.message'|trans", $header, 'the header shows the buyer message.');
}

function test_sw_storefront_review_snippets_ship_in_every_locale()
{
    $blocked = new \Paymos\Plugin\InvoiceReplacementBlockedException(
        \Paymos\Plugin\InvoiceReplacementResult::blocked('inv_old', 'awaiting_payment', \Paymos\Plugin\InvoiceReplacementResult::REASON_OPEN)
    );

    foreach (sw_storefront_snippet_files() as $locale => $path) {
        assertTrueValue(is_file($path), $locale . ': storefront snippet file exists.');
        $tree = json_decode((string) file_get_contents($path), true);
        assertTrueValue(is_array($tree), $locale . ': the snippet file is valid JSON.');
        assertSameValue(2, count(explode('.', basename($path, '.json'))), $locale . ': the file is named <name>.<locale>.json, so Shopware reads it as ' . $locale . '.');

        foreach (array('paymosPayments.reviewRequired.heading', 'paymosPayments.reviewRequired.message') as $key) {
            $value = sw_snippet_value($tree, $key);
            assertTrueValue($value !== null && trim($value) !== '', $locale . ': ' . $key . ' is set.');
        }
    }

    $english = json_decode((string) file_get_contents(sw_storefront_snippet_files()['en-GB']), true);
    assertSameValue($blocked->getMessage(), sw_snippet_value($english, 'paymosPayments.reviewRequired.message'), 'en-GB: the message is the SDK buyer message.');
}
