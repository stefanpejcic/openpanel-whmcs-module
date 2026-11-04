<?php
/*
    "OpenPanel Account" field on the admin service page, rendered by
    openpanelRenderView() with data from openpanelAccountView().
    Escape every value with $e().

    @var callable $e
    @var array    $usage      label, used, limit, percent (null when unlimited)
    @var array    $domains    url, php_version, docroot, sites[]
    @var int      $siteCount
    @var array    $planRows   label => value, empty when the user has no plan
    @var array    $account    label => value
*/
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}
?>
<div class="openpanel-account">
<?php if ($usage): ?>
    <div class="panel panel-default">
        <div class="panel-heading"><strong>Storage</strong></div>
        <div class="panel-body">
<?php foreach ($usage as $row): ?>
<?php $barClass = $row['percent'] === null ? '' : ($row['percent'] >= 90 ? ' progress-bar-danger' : ($row['percent'] >= 75 ? ' progress-bar-warning' : '')); ?>
            <div class="text-muted">
                <?= $e($row['label']) ?>
                <span class="pull-right"><?= $e($row['used']) ?> / <?= $e($row['limit']) ?><?= $row['percent'] !== null ? ' (' . $e($row['percent']) . '%)' : '' ?></span>
            </div>
            <div class="progress">
                <div class="progress-bar<?= $barClass ?>" role="progressbar" style="width: <?= (float) $row['percent'] ?>%;"></div>
            </div>
<?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

    <div class="panel panel-default">
        <div class="panel-heading">
            <strong>Domains</strong>
            <span class="text-muted">(<?= count($domains) ?> domains, <?= (int) $siteCount ?> sites)</span>
        </div>
        <div class="panel-body">
<?php if ($domains): ?>
<?php foreach ($domains as $domain): ?>
            <div class="well well-sm">
                <strong><?= $e($domain['url']) ?></strong>
                <div class="text-muted small">PHP: <?= $e($domain['php_version']) ?>, Docroot: <?= $e($domain['docroot']) ?></div>
<?php if ($domain['sites']): ?>
                <ul class="small" style="margin-bottom: 0;">
<?php foreach ($domain['sites'] as $site): ?>
                    <li><?= $e($site['name']) ?> (<?= $e($site['type']) ?>, Admin: <?= $e($site['admin_email']) ?>, Created: <?= $e($site['created']) ?>)</li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>
            </div>
<?php endforeach; ?>
<?php else: ?>
            <span class="text-muted">None</span>
<?php endif; ?>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading"><strong>Plan Limits</strong></div>
        <div class="panel-body">
<?php if ($planRows): ?>
            <table class="table table-condensed table-bordered" style="margin-bottom: 0;">
                <tbody>
<?php foreach ($planRows as $label => $value): ?>
                    <tr><th><?= $e($label) ?></th><td><?= $e($value) ?></td></tr>
<?php endforeach; ?>
                </tbody>
            </table>
<?php else: ?>
            <span class="text-muted">—</span>
<?php endif; ?>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading"><strong>Account Information</strong></div>
        <div class="panel-body">
            <table class="table table-condensed table-bordered" style="margin-bottom: 0;">
                <tbody>
<?php foreach ($account as $label => $value): ?>
                    <tr><th><?= $e($label) ?></th><td><?= $e($value) ?></td></tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
