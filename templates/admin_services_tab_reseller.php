<?php
/*
    "OpenAdmin Reseller" field on the admin service page of a Reseller
    Account product, rendered by openpanelRenderView() with data from
    openpanelResellerView(). Escape every value with $e().

    @var callable $e
    @var array    $usage    label, used, limit, percent (null when unlimited)
    @var array    $account  label => value
*/
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}
?>
<div class="openpanel-account">
    <div class="panel panel-default">
        <div class="panel-heading"><strong>Usage</strong></div>
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

    <div class="panel panel-default">
        <div class="panel-heading"><strong>Reseller</strong></div>
        <table class="table table-condensed" style="margin-bottom: 0;">
<?php foreach ($account as $label => $value): ?>
            <tr><th style="width: 40%;"><?= $e($label) ?></th><td><?= $e($value) ?></td></tr>
<?php endforeach; ?>
        </table>
    </div>
</div>
