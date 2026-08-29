<?php
/**
 * Script dependencies for editor.js.
 *
 * A build step would normally emit this file. There is none here, so the list
 * is kept by hand: block.json points at editor.js, and WordPress reads the
 * matching .asset.php next to it to know what to enqueue first.
 *
 * @package KeyWordCloud
 */

return array(
    'dependencies' => array(
        'wp-blocks',
        'wp-element',
        'wp-block-editor',
        'wp-components',
        'wp-i18n',
        'wp-server-side-render',
    ),
    'version'      => '2.11.1',
);
