<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <title>@yield('subject', 'Cherishly')</title>
  <style>
    /* Reset */
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body,
    table,
    td,
    a {
      -webkit-text-size-adjust: 100%;
      -ms-text-size-adjust: 100%;
    }

    table,
    td {
      mso-table-lspace: 0pt;
      mso-table-rspace: 0pt;
    }

    img {
      -ms-interpolation-mode: bicubic;
      border: 0;
      outline: none;
      text-decoration: none;
    }

    body {
      font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background-color: #F5EFE8;
      margin: 0;
      padding: 0;
    }

    .email-wrapper {
      width: 100%;
      background-color: #F5EFE8;
      padding: 40px 16px;
    }

    .email-container {
      max-width: 580px;
      margin: 0 auto;
    }

    /* Header */
    .email-header {
      text-align: center;
      padding: 32px 0 24px;
    }

    .email-header img {
      height: 48px;
      width: auto;
    }

    /* Card */
    .email-card {
      background-color: #FFFFFF;
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
    }

    /* Card top accent */
    .email-card-accent {
      height: 4px;
      background: linear-gradient(90deg, #3D0C0C 0%, #E8604A 50%, #C9A84C 100%);
    }

    /* Card body */
    .email-body {
      padding: 40px 48px;
    }

    .email-greeting {
      font-size: 22px;
      font-weight: 700;
      color: #1A0A0A;
      margin-bottom: 12px;
      line-height: 1.3;
    }

    .email-text {
      font-size: 15px;
      color: #6B5B5B;
      line-height: 1.7;
      margin-bottom: 16px;
    }

    .email-text strong {
      color: #1A0A0A;
    }

    /* OTP Box */
    .otp-box {
      background: linear-gradient(135deg, #FFF8F6 0%, #FFF3EE 100%);
      border: 1.5px dashed #E8604A;
      border-radius: 12px;
      text-align: center;
      padding: 28px 24px;
      margin: 28px 0;
    }

    .otp-label {
      font-size: 12px;
      font-weight: 600;
      color: #9B7B7B;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      margin-bottom: 12px;
    }

    .otp-code {
      font-size: 42px;
      font-weight: 800;
      color: #3D0C0C;
      letter-spacing: 0.18em;
      line-height: 1;
    }

    .otp-expiry {
      font-size: 12px;
      color: #9B7B7B;
      margin-top: 12px;
    }

    .otp-expiry strong {
      color: #E8604A;
    }

    /* Warning box */
    .warning-box {
      background-color: #FFF8EE;
      border-left: 3px solid #C9A84C;
      border-radius: 0 8px 8px 0;
      padding: 14px 16px;
      margin: 20px 0;
    }

    .warning-box p {
      font-size: 13px;
      color: #6B5B5B;
      line-height: 1.6;
    }

    .warning-box strong {
      color: #1A0A0A;
    }

    /* Divider */
    .email-divider {
      border: none;
      border-top: 1px solid #F0E8E0;
      margin: 28px 0;
    }

    /* Button */
    .email-btn {
      display: inline-block;
      background-color: #3D0C0C;
      color: #FFFFFF !important;
      font-size: 14px;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-decoration: none;
      padding: 14px 32px;
      border-radius: 100px;
      text-align: center;
      margin: 8px 0;
    }

    /* Footer */
    .email-footer {
      padding: 32px 48px 24px;
      border-top: 1px solid #F0E8E0;
      text-align: center;
    }

    /* Social icons */
    .social-links {
      margin-bottom: 20px;
    }

    .social-link {
      display: inline-block;
      width: 36px;
      height: 36px;
      background-color: #3D0C0C;
      border-radius: 50%;
      margin: 0 4px;
      text-align: center;
      line-height: 36px;
      text-decoration: none;
    }

    .social-link img {
      width: 16px;
      height: 16px;
      vertical-align: middle;
      margin-top: -2px;
    }

    .footer-links {
      margin-bottom: 16px;
    }

    .footer-link {
      font-size: 12px;
      color: #9B7B7B;
      text-decoration: none;
      margin: 0 10px;
    }

    .footer-link:hover {
      color: #E8604A;
      text-decoration: underline;
    }

    .footer-address {
      font-size: 11px;
      color: #B8A8A8;
      line-height: 1.6;
      margin-bottom: 12px;
    }

    .footer-copy {
      font-size: 11px;
      color: #B8A8A8;
    }

    .footer-unsubscribe {
      font-size: 11px;
      color: #B8A8A8;
      margin-top: 8px;
    }

    .footer-unsubscribe a {
      color: #9B7B7B;
      text-decoration: underline;
    }

    /* Responsive */
    @media only screen and (max-width: 600px) {
      .email-body {
        padding: 28px 24px;
      }

      .email-footer {
        padding: 24px 24px 20px;
      }

      .otp-code {
        font-size: 34px;
      }

      .email-greeting {
        font-size: 20px;
      }
    }
  </style>
</head>

<body>
  <div class="email-wrapper">
    <div class="email-container">

      <!-- Logo Header -->
      <div class="email-header">
        <img src="https://assets.cherishlyng.com/uploads/general/cherishly-1-original__1__20260801_143235_128f1e3e.png"
          alt="Cherishly" />
      </div>

      <!-- Card -->
      <div class="email-card">
        <div class="email-card-accent"></div>
        <div class="email-body">
          @yield('content')
        </div>

        <!-- Footer -->
        <div class="email-footer">

          <!-- Social Links -->
          <div class="social-links">
            <a href="https://facebook.com/cherishly" class="social-link" target="_blank">
              <img src="https://cdn-icons-png.flaticon.com/512/733/733547.png" alt="Facebook" />
            </a>
            <a href="https://twitter.com/cherishly" class="social-link" target="_blank">
              <img src="https://cdn-icons-png.flaticon.com/512/733/733579.png" alt="Twitter" />
            </a>
            <a href="https://instagram.com/cherishly" class="social-link" target="_blank">
              <img src="https://cdn-icons-png.flaticon.com/512/733/733558.png" alt="Instagram" />
            </a>
          </div>

          <!-- Footer Links -->
          <div class="footer-links">
            <a href="https://cherishlyng.com" class="footer-link">Home</a>
            <a href="https://cherishlyng.com/about" class="footer-link">About</a>
            <a href="https://cherishlyng.com/contact" class="footer-link">Contact</a>
            <a href="https://cherishlyng.com/privacy" class="footer-link">Privacy Policy</a>
            <a href="https://cherishlyng.com/terms" class="footer-link">Terms</a>
          </div>

          <!-- Address -->
          <p class="footer-address">
            Cherishly Technologies Ltd.<br />
            Lagos, Nigeria
          </p>

          <!-- Copyright -->
          <p class="footer-copy">
            &copy; {{ date('Y') }} Cherishly. All rights reserved.
          </p>

          <!-- Unsubscribe -->
          <p class="footer-unsubscribe">
            You received this email because you have an account on Cherishly.<br />
            <a href="https://cherishlyng.com/unsubscribe">Unsubscribe</a> from these emails.
          </p>

        </div>
      </div>

    </div>
  </div>
</body>

</html>