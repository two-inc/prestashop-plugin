<?php

/**
 * Minimal PrestaShop core-class stubs for static analysis only.
 *
 * This file is never loaded at runtime — PrestaShop core provides the real
 * classes when the module is installed in a shop. It exists solely so
 * phpstan can resolve the base classes this module extends (`extends X`
 * requires phpstan's reflection to know X exists; without a stub, phpstan
 * treats these as "non-ignorable" errors that cannot be baselined).
 *
 * Keep this file to the bare minimum needed to unblock class resolution —
 * do not add method bodies or business logic here. Everything else
 * (undefined methods/properties on these classes, e.g. Configuration::get())
 * is handled by phpstan-baseline.neon instead, since those errors are
 * ignorable.
 */

declare(strict_types=1);

if (!class_exists('Module', false)) {
    class Module
    {
    }
}

if (!class_exists('PaymentModule', false)) {
    class PaymentModule extends Module
    {
    }
}

if (!class_exists('Controller', false)) {
    class Controller
    {
    }
}

if (!class_exists('FrontController', false)) {
    class FrontController extends Controller
    {
    }
}

if (!class_exists('ModuleFrontController', false)) {
    class ModuleFrontController extends FrontController
    {
    }
}

if (!class_exists('AdminController', false)) {
    class AdminController extends Controller
    {
    }
}

if (!class_exists('ModuleAdminController', false)) {
    class ModuleAdminController extends AdminController
    {
    }
}

if (!class_exists('CustomerAddressFormatterCore', false)) {
    class CustomerAddressFormatterCore
    {
    }
}
