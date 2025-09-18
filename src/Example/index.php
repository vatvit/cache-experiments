<?php

// обычный путь — доменные дефолты из конструктора/движка
$p = $catalogCache->getProduct($id);

// точечный оверрайд TTL/режимов только для этого вызова
$strict = GetPolicy::create(hardSec: 3600, softSec: 120)
    ->withRefreshMode(RefreshMode::SYNC)
    ->withFailMode(FailMode::CLOSED);

$p2 = $catalogCache->getProduct($id, $strict);

// инвалидация «освежением» (по умолчанию REFRESH)
$catalogCache->invalidateProduct($id);

// обход кэша
$pRaw = $catalogCache->raw()->byId($id);
