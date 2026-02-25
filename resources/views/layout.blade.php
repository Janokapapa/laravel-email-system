<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <title>{{ config('app.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <style>
        @media only screen and (max-width: 600px) {
            .inner-body {
                width: 100% !important;
            }
            .footer {
                width: 100% !important;
            }
        }
        @media only screen and (max-width: 500px) {
            .button {
                width: 100% !important;
            }
        }
    </style>
</head>
<body style="box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol'; position: relative; -webkit-text-size-adjust: none; background-color: #ffffff; color: #718096; height: 100%; line-height: 1.4; margin: 0; padding: 0; width: 100% !important;">

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background-color: #edf2f7; margin: 0; padding: 0; width: 100%;">
    <tr>
        <td align="center">
            <table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0; padding: 0; width: 100%;">
                <tr>
                    <td class="header" style="padding: 25px 0; text-align: center;">
                        <a href="{{ config('email-system.website_url', config('app.url')) }}" style="color: #3d4852; font-size: 19px; font-weight: bold; text-decoration: none;">
                            @if(config('email-system.logo_url'))
                                <img src="{{ config('email-system.logo_url') }}" alt="{{ config('app.name') }}" style="max-height: 60px; max-width: 200px; border: none;"><br>
                            @endif
                            {{ config('app.name') }}
                        </a>
                    </td>
                </tr>

                <!-- Email Body -->
                <tr>
                    <td class="body" width="100%" cellpadding="0" cellspacing="0" style="background-color: #edf2f7; border-bottom: 1px solid #edf2f7; border-top: 1px solid #edf2f7; margin: 0; padding: 0; width: 100%;">
                        <table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation" style="background-color: #ffffff; border-color: #e8e5ef; border-radius: 2px; box-shadow: 0 2px 0 rgba(0, 0, 150, 0.025), 2px 4px 0 rgba(0, 0, 150, 0.015); margin: 0 auto; width: 570px;">
                            <!-- Body content -->
                            <tr>
                                <td class="content-cell" style="padding: 32px;">
                                    @yield('content')
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td>
                        <table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto; text-align: center; width: 570px;">
                            <tr>
                                <td class="content-cell" align="center" style="padding: 32px;">
                                    <p style="color: #b0adc5; font-size: 12px; text-align: center;">
                                        &copy; {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}
                                    </p>

                                    @if (isset($unsubscribeUrl))
                                        <p style="font-size: 12px; text-align: center;">
                                            <a href="{{ $unsubscribeUrl }}" style="color: #b0adc5; text-decoration: underline;">
                                                {{ __('Unsubscribe from newsletter') }}
                                            </a>
                                        </p>
                                    @endif
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
@if (isset($emailLog) && $emailLog->id)
<!-- Tracking Pixel for Email Opens -->
<img src="{{ URL::signedRoute('email-system.track.open', ['log_id' => $emailLog->id]) }}" alt="" width="1" height="1" style="display:none;" />
@endif
</html>
