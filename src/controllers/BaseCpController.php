<?php

namespace ghoststreet\craftsmartsearch\controllers;

/**
 * Base for the control panel pages and their XHR actions: admins only.
 */
abstract class BaseCpController extends BaseApiController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin(false);

        return true;
    }
}
