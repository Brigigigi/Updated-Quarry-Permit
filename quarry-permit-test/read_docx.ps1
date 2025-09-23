param([string[]]$Files)
Add-Type -AssemblyName System.IO.Compression.FileSystem
function Get-DocxText([string]$path){
  $zip=[System.IO.Compression.ZipFile]::OpenRead($path)
  try{
    $entry=$zip.Entries | Where-Object { $_.FullName -eq 'word/document.xml' }
    if(-not $entry){ return '' }
    $sr=[System.IO.StreamReader]::new($entry.Open())
    $xml=$sr.ReadToEnd()
    $sr.Close()
    $plain = [System.Text.RegularExpressions.Regex]::Replace($xml,'<[^>]+>',' ')
    $plain = $plain -replace '&amp;','&' -replace '&lt;','<' -replace '&gt;','>' -replace '&quot;','"' -replace '&apos;','''
    $plain = [System.Text.RegularExpressions.Regex]::Replace($plain,'\s+',' ')
    return $plain.Trim()
  }
  finally{ $zip.Dispose() }
}
foreach($f in $Files){
  if(Test-Path $f){
    Write-Output "--- $f ---"
    $t = Get-DocxText $f
    if($t.Length -gt 4000){ $t.Substring(0,4000) } else { $t }
  } else {
    Write-Output "Missing: $f"
  }
}
