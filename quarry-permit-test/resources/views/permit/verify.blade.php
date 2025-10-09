<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quarry Permit Verification</title>
    <style>
        :root { color-scheme: light dark; }
        body { font-family: Arial, sans-serif; margin: 0; padding: 24px; background: #f5f5f5; }
        .card { max-width: 520px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 24px 28px; box-shadow: 0 12px 30px rgba(0,0,0,0.08); }
        h1 { margin-top: 0; font-size: 1.6rem; }
        .timestamp { color: #666; font-size: 0.9rem; margin-bottom: 18px; }
        .status { padding: 12px 16px; border-radius: 8px; font-weight: 600; margin-bottom: 18px; }
        .status--success { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; }
        .status--warning { background: #fefce8; color: #92400e; border: 1px solid #fde68a; }
        .status--error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .flags { list-style: none; padding: 0; margin: 0 0 18px; display: flex; gap: 16px; flex-wrap: wrap; }
        .flags li { font-size: 0.95rem; }
        .flags strong { font-weight: 700; }
        .details { margin: 0; }
        .details dt { font-weight: 600; margin-top: 12px; }
        .details dd { margin: 4px 0 0 0; color: #1f2937; }
        .footnote { margin-top: 24px; font-size: 0.85rem; color: #4b5563; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Quarry Permit Verification</h1>
        <p class="timestamp">Checked {{ optional($verifiedAt)->format('Y-m-d H:i') }}</p>
        @php
            $statusClass = $validSignature && $paid && $granted ? 'status--success' : ($validSignature ? 'status--warning' : 'status--error');
        @endphp
        <p class="status {{ $statusClass }}">{{ $message }}</p>
        <ul class="flags">
            <li>Paid: <strong>{{ $paid ? 'Yes' : 'No' }}</strong></li>
            <li>Granted: <strong>{{ $granted ? 'Yes' : 'No' }}</strong></li>
        </ul>
        @if($allowDetails && !empty($details))
            <dl class="details">
                @foreach($details as $label => $value)
                    <dt>{{ $label }}</dt>
                    <dd>{{ $value }}</dd>
                @endforeach
            </dl>
        @elseif($validSignature)
            <p class="footnote">Permit details are hidden until payment is confirmed and the permit is granted.</p>
        @else
            <p class="footnote">Unable to verify this permit. Please contact the issuing office for assistance.</p>
        @endif
    </div>
</body>
</html>
