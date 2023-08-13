<?php
defined('ABSPATH') or die;
/**
 * Page template with raw html
 */

global $post;
$data_provider = np_data_provider($post->ID);
$headerNp = $data_provider->getNpHeader();
$footerNp = $data_provider->getNpFooter();
$tmpPath = get_template_directory();
$cookiesSection = '';
$cookiesConsent = NpMeta::get('cookiesConsent') ? json_decode(NpMeta::get('cookiesConsent'), true) : '';
if ($cookiesConsent && (!$cookiesConsent['hideCookies'] || $cookiesConsent['hideCookies'] === 'false')) {
    $cookiesSection = $cookiesConsent['publishCookiesSection'];
    $cookiesSection = $data_provider->fixImagePaths($cookiesSection);
}
ob_start();
?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <?php wp_head(); ?>
    </head>
    <body class="<?php echo $data_provider->getPageBodyClass(); ?>"
          style="<?php echo $data_provider->getPageBodyStyle(); ?>"
          data-bg="<?php echo $data_provider->getPageBodyDataBg(); ?>">
<?php if (version_compare($wp_version, '5.2', '>=')) {
    wp_body_open();
} ?>
    <?php $headerItem = '';
    if ($headerNp && !$data_provider->getHideHeader()) {
        $headerItem = json_decode($headerNp, true);
        $publishHeader = $data_provider->getTranslation($headerItem, 'header');
        $publishHeader = Nicepage::processFormCustomPhp($publishHeader, 'header');
        $publishHeader = Nicepage::processContent($publishHeader, array('templateName' => 'header'));
    }
    if ($headerItem) {
        if (false === strpos($headerItem['styles'], '<style>')) {
            $headerItem['styles'] = '<style>' . $headerItem['styles'] . '</style>';
        }
        echo $headerItem['styles'];
        echo $publishHeader;
    }
    the_post();
    the_content();
    $footerItem = '';
    if ($footerNp && !$data_provider->getHideFooter()) {
        $footerItem = json_decode($footerNp, true);
        $publishFooter = $data_provider->getTranslation($footerItem, 'footer');
        $publishFooter = Nicepage::processFormCustomPhp($publishFooter, 'footer');
        $publishFooter = Nicepage::processContent($publishFooter, array('templateName' => 'footer'));
    }
    if ($footerItem) {
        if (false === strpos($footerItem['styles'], '<style>')) {
            $footerItem['styles'] = '<style>' . $footerItem['styles'] . '</style>';
        }
        echo $footerItem['styles'];
        echo $publishFooter;
    }
    wp_footer(); ?>
    <?php echo $cookiesSection; ?>
    </body>
    </html>
<?php
$htmlDocument = ob_get_clean();
$htmlDocument = $data_provider->addPublishDialogToBody($htmlDocument, $headerItem, $footerItem);
echo $htmlDocument;