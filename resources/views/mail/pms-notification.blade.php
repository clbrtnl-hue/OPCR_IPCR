<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $headline }}</title>
</head>
<body style="margin:0; padding:0; background:#f4f6f8; color:#1a1a1a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8; padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px; width:100%; background:#ffffff; border:1px solid #e4e7eb;">
                    <tr>
                        <td style="padding:28px 32px 8px; font-family:Georgia, 'Times New Roman', serif;">
                            <p style="margin:0; font-size:12px; letter-spacing:0.12em; text-transform:uppercase; color:#1e3a72;">OCC PMS</p>
                            <h1 style="margin:12px 0 0; font-size:22px; line-height:1.3; font-weight:normal; color:#1a1a1a;">{{ $headline }}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px 28px; font-family:Georgia, 'Times New Roman', serif; font-size:16px; line-height:1.55; color:#333333;">
                            @if ($actorName)
                                <p style="margin:0 0 16px; color:#555555;">From {{ $actorName }}</p>
                            @endif
                            @if ($detail)
                                <p style="margin:0 0 20px;">{{ $detail }}</p>
                            @endif
                            @if ($actionUrl)
                                <p style="margin:0;">
                                    <a href="{{ $actionUrl }}" style="display:inline-block; background:#1e3a72; color:#ffffff; text-decoration:none; padding:10px 16px; font-size:14px;">Open in PMS</a>
                                </p>
                                <p style="margin:16px 0 0; font-size:13px; color:#777777; word-break:break-all;">{{ $actionUrl }}</p>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
