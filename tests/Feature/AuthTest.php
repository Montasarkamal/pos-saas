<?php

require_once __DIR__ . '/../../inc/auth.php';

beforeEach(function () {
    $_SESSION = [];
});

it('gera e valida o csrf token', function () {
    $token = csrf_token();
    expect($token)->toBeString()->toHaveLength(64);

    expect(csrf_check($token))->toBeTrue();
    expect(csrf_check(strrev($token)))->toBeFalse();
    expect(csrf_check('x' . substr($token, 1)))->toBeFalse();
    expect(csrf_check(null))->toBeFalse();
    expect(csrf_check(''))->toBeFalse();
});

it('mantém o mesmo csrf na mesma sessão', function () {
    expect(csrf_token())->toBe(csrf_token());
});

it('agency_scope_sql filtra pela agência para não-superadmin', function () {
    $_SESSION['role'] = 'admin';
    $_SESSION['agency_id'] = 7;

    [$where, $params] = agency_scope_sql();
    expect($where)->toBe('agency_id = ?');
    expect($params)->toBe([7]);

    [$where2, $params2] = agency_scope_sql('owner_id');
    expect($where2)->toBe('owner_id = ?');
    expect($params2)->toBe([7]);
});

it('agency_scope_sql ignora o filtro de agência para superadmin', function () {
    $_SESSION['role'] = 'superadmin';

    [$where, $params] = agency_scope_sql();
    expect($where)->toBe('1=1');
    expect($params)->toBe([]);
});

it('is_superadmin e is_admin respeitam os papéis', function () {
    $_SESSION['role'] = 'superadmin';
    expect(is_superadmin())->toBeTrue();
    expect(is_admin())->toBeTrue();

    $_SESSION['role'] = 'admin';
    expect(is_superadmin())->toBeFalse();
    expect(is_admin())->toBeTrue();

    $_SESSION['role'] = 'operador';
    expect(is_superadmin())->toBeFalse();
    expect(is_admin())->toBeFalse();
});

it('is_master_user detecta superadmin de forma case-insensitive', function () {
    expect(is_master_user(['role' => 'SUPERADMIN']))->toBeTrue();
    expect(is_master_user(['role' => 'superadmin']))->toBeTrue();
    expect(is_master_user(['role' => 'admin']))->toBeFalse();
    expect(is_master_user([]))->toBeFalse();
});

it('user_id e agency_id leem da sessão', function () {
    expect(user_id())->toBe(0);
    expect(agency_id())->toBe(0);

    $_SESSION['uid'] = 42;
    $_SESSION['agency_id'] = 9;
    expect(user_id())->toBe(42);
    expect(getCurrentUserId())->toBe(42);
    expect(agency_id())->toBe(9);
});