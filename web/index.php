<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Generator Sertifikat</title>
</head>
<body>
  <h2>Generate PDF dari Template</h2>

  <form action="run.php" method="post" enctype="multipart/form-data">
    <p>
      <label>Template (PNG/JPG):</label><br>
      <input type="file" name="template" accept=".png,.jpg,.jpeg" required>
    </p>
    <p>
      <label>Data (Excel .xlsx):</label><br>
      <input type="file" name="data" accept=".xlsx" required>
    </p>
    <button type="submit">Generate</button>
  </form>
</body>
</html>
