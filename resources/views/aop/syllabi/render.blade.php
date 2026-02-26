<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Syllabus</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; color:#111; line-height:1.35; margin:24px; }
    h2,h3,h4 { margin: 0 0 8px 0; }
    .muted { color:#555; }
    ul { margin: 0; padding-left:18px; }
  </style>
</head>
<body>
  @include('aop.syllabi.partials.syllabus', ['syllabus' => $syllabus])
</body>
</html>
