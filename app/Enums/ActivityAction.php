<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum ActivityAction: string
{
    use HasValues;

    case Login = 'login';
    case LoginFailed = 'login_failed';
    case Logout = 'logout';
    case PasswordChanged = 'password_changed';
    case PasswordReset = 'password_reset';
    case ProfileUpdated = 'profile_updated';
    case EmailVerified = 'email_verified';
    case CompanyRegistered = 'company_registered';
    case CompanySettingsUpdated = 'company_settings_updated';
    case LogoChanged = 'logo_changed';
    case BatchCreated = 'batch_created';
    case BatchDeleted = 'batch_deleted';
    case ImageProcessed = 'image_processed';
    case ImageFailed = 'image_failed';
    case AdminCompanyCreated = 'admin_company_created';
    case AdminCompanyUpdated = 'admin_company_updated';
    case AdminCompanyBlocked = 'admin_company_blocked';
    case AdminCompanyUnblocked = 'admin_company_unblocked';
    case AdminCompanyDeleted = 'admin_company_deleted';
    case AdminPasswordResetSent = 'admin_password_reset_sent';
    case AdminStorageDeleted = 'admin_storage_deleted';
    case AdminSettingsUpdated = 'admin_settings_updated';
}
