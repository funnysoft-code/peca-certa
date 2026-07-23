<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Convite — {{ $appName }}</title>
</head>
<body style="margin:0;padding:0;background-color:#0A0A0A;font-family:Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#F2F4F8;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#0A0A0A;padding:40px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:520px;background-color:#111111;border:1px solid #1f1f1f;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="padding:28px 28px 8px 28px;">
                            <div style="font-family:'Space Grotesk',Inter,ui-sans-serif,system-ui,sans-serif;font-size:13px;letter-spacing:0.12em;text-transform:uppercase;color:#4eb8a4;font-weight:600;">
                                {{ $appName }}
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 28px 0 28px;">
                            <h1 style="margin:0;font-family:'Space Grotesk',Inter,ui-sans-serif,system-ui,sans-serif;font-size:24px;line-height:1.25;font-weight:600;color:#F2F4F8;">
                                Foi convidado
                            </h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px 0 28px;">
                            <p style="margin:0;font-size:15px;line-height:1.6;color:#a8b0bd;">
                                Olá {{ $user->name }},
                            </p>
                            <p style="margin:12px 0 0 0;font-size:15px;line-height:1.6;color:#a8b0bd;">
                                Foi criado um acesso para si em <strong style="color:#F2F4F8;">{{ $appName }}</strong>.
                                Defina a sua palavra-passe para começar a usar a plataforma.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 28px 8px 28px;" align="center">
                            <a href="{{ $url }}"
                               style="display:inline-block;background-color:#4eb8a4;color:#0A0A0A;text-decoration:none;font-weight:600;font-size:15px;padding:12px 24px;border-radius:8px;">
                                Definir palavra-passe
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px 28px 28px;">
                            <p style="margin:0;font-size:12px;line-height:1.5;color:#6b7280;">
                                Se o botão não funcionar, copie e cole este link no browser:
                            </p>
                            <p style="margin:8px 0 0 0;font-size:12px;line-height:1.5;word-break:break-all;color:#4eb8a4;">
                                {{ $url }}
                            </p>
                            <p style="margin:16px 0 0 0;font-size:12px;line-height:1.5;color:#6b7280;">
                                Se não esperava este convite, pode ignorar este email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
