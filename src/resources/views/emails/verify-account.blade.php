<!DOCTYPE html>
<html lang="en" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
<head>
    <meta charset="utf-8">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light">
    <title>Verify your email</title>
</head>
<body style="margin:0; background-color:#f8fafc; color:#0f172a;">

    <!-- Outer wrapper -->
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="padding:24px 0; background-color:#f8fafc;">
        <tr>
            <td align="center">

                <!-- Card -->
                <table width="100%" cellpadding="0" cellspacing="0" role="presentation"
                    style="max-width:600px; background-color:#ffffff; border-radius:16px;
                           border:1px solid #e2e8f0; box-shadow:0 8px 24px rgba(15,23,42,0.06); overflow:hidden;">

                    <!-- Header / Brand -->
                    <tr>
                        <td style="background-image:linear-gradient(to right,#0891b2,#0d9488);
                                   padding:24px; text-align:center;">
                            <img src="https://avinaq.com/logonew1.jpg"
                                 alt="ISC Logo"
                                 style="max-width:120px;height:auto;display:block;margin:0 auto;border-radius:8px;">
                            <div style="color:#020a00;font-size:12px;margin-top:12px;font-weight:500;letter-spacing:.03em;">
                                Industrial Supplies Center
                            </div>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:32px 28px 24px; color:#0f172a; font-size:15px; line-height:1.5;">
                            <h1 style="margin:0 0 16px; font-size:20px; font-weight:600; color:#0f172a;">
                                Hello {{ $username }},
                            </h1>

                            <p style="margin:0 0 12px; color:#475569; font-size:14px; line-height:1.5;">
                                Thank you for creating an account with ISC.
                            </p>

                            <p style="margin:0 0 12px; color:#475569; font-size:14px; line-height:1.5;">
                                To activate your account and start using our services, please confirm your email address.
                            </p>

                            <!-- Button -->
                            <div style="text-align:center; margin:24px 0 8px;">
                                <a href="{{ $verificationUrl }}"
                                   style="text-decoration:none; display:inline-block;
                                          background-image:linear-gradient(to right,#0891b2,#0d9488);
                                          color:#000000; font-size:14px; font-weight:600;
                                          padding:12px 20px; border-radius:8px;
                                          box-shadow:0 4px 12px rgba(0,0,0,0.08);">
                                    Activate My Account
                                </a>
                            </div>

                            <p style="text-align:center; color:#64748b; font-size:12px; line-height:1.5; margin:8px 0 16px;">
                                لتفعيل حسابك يرجى الضغط على الزر أعلاه
                            </p>

                            <p style="margin:16px 0 12px; color:#475569; font-size:14px; line-height:1.5;">
                                This link will expire in 60 minutes for security.
                            </p>

                            <p style="margin:0 0 12px; color:#475569; font-size:14px; line-height:1.5;">
                                If you did not create this account, you can safely ignore this email.
                            </p>

                            <!-- Fallback URL -->
                            <div style="margin-top:24px; border-top:1px solid #e2e8f0; padding-top:16px;">
                                <p style="color:#94a3b8; font-size:12px; line-height:1.5; word-break:break-word; margin:0;">
                                    If the button above doesn’t work, copy and paste this link into your browser:<br>
                                    <a href="{{ $verificationUrl }}" style="color:#0d9488; word-break:break-all;">
                                        {{ $verificationUrl }}
                                    </a>
                                </p>
                                <p style="color:#94a3b8; font-size:12px; text-align:right; direction:rtl; margin-top:12px;">
                                    إذا لم يعمل الزر، انسخ الرابط وضعه في المتصفح خلال ٦٠ دقيقة.
                                </p>
                            </div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#f8fafc; padding:20px 28px; text-align:center;">
                            <p style="margin:0; color:#94a3b8; font-size:12px; line-height:1.4;">
                                Thank you,<br>
                                ISC Support Team
                            </p>

                            <p style="margin:8px 0 0; color:#94a3b8; font-size:11px; line-height:1.4;">
                                © {{ date('Y') }} ISC. All rights reserved.
                            </p>

                            <p style="margin:4px 0 0; color:#94a3b8; font-size:11px; line-height:1.4;">
                                This email was sent to you because an account was created using this address.
                                If this wasn’t you, you can ignore it.
                            </p>
                        </td>
                    </tr>

                </table>
                <!-- /Card -->

            </td>
        </tr>
    </table>

</body>
</html>
