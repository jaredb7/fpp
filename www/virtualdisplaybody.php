
<script>
var cellColors = [];
var scaleMap = [];
var base64 = [];
<?
// 16:9 canvas by default, but can be overwridden in wrapper script
if (!isset($canvasWidth))
	$canvasWidth = 1024;

if (!isset($canvasHeight))
	$canvasHeight = 576;

$base64 = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz+/"; // Base64 index table
for ($i = 0; $i < 64; $i++)
{
	printf("base64['%s'] = '%02X';\n", substr($base64, $i, 1), $i << 2);
}

$f = fopen($settings['configDirectory'] . '/virtualdisplaymap', "r");
if ($f) {
	$line = fgets($f);
	if (preg_match('/^#/', $line))
		$line = fgets($f);
	$line = trim($line);
	$parts = explode(',', $line);
	$previewWidth = $parts[0];
	$previewHeight = $parts[1];

	if (($previewWidth / $previewHeight) > ($canvasWidth / $canvasHeight))
		$canvasHeight = (int)($canvasWidth * $previewHeight / $previewWidth);
	else
		$canvasWidth = (int)($canvasHeight * $previewWidth / $previewHeight);

	echo "var previewWidth = " . $previewWidth . ";\n";
	echo "var previewHeight = " . $previewHeight . ";\n";

	$scale = 1.0 * $canvasWidth / $previewWidth;

	$scaleMap = Array();

	while (!feof($f)) {
		$line = fgets($f);
		if (($line == "") || (preg_match('/^#/', $line)))
			continue;

		$line = trim($line);
		$entry = explode(",", $line, 6);

		$ox = $entry[0];
		$oy = $previewHeight - $entry[1];
		$oz = $entry[2];
		$x = (int)($ox * $scale);
		$y = (int)($oy * $scale);
		$z = (int)($oz * $scale);
		$ch = $entry[3];
		$colors = $entry[4];
		$iy = $canvasHeight - $y;

		if (($ox >= 4096) || ($oy >= 4096))
			$key = substr($base64, ($ox >> 12) & 0x3f, 1) .
					substr($base64, ($ox >> 6) & 0x3f, 1) .
					substr($base64, $ox & 0x3f, 1) .
					substr($base64, ($oy >> 12) & 0x3f, 1) .
					substr($base64, ($oy >> 6) & 0x3f, 1) .
					substr($base64, $oy & 0x3f, 1);
		else
			$key = substr($base64, ($ox >> 6) & 0x3f, 1) .
					substr($base64, $ox & 0x3f, 1) .
					substr($base64, ($oy >> 6) & 0x3f, 1) .
					substr($base64, $oy & 0x3f, 1);

		if (!isset($scaleMap[$key]))
		{
			$scaleMap[$key] = 1;
			echo "scaleMap['" . $key . "'] = { x: $x, y: $y, ox: $ox, oy: $oy };\n";
			echo "cellColors['$x,$y'] = { x: $x, y: $y, color: '#000000'};\n";
		}
	}
	fclose($f);
}

?>

var canvasWidth = <? echo $canvasWidth; ?>;
var canvasHeight = <? echo $canvasHeight; ?>;
var evtSource;
var ctx;
var buffer;
var bctx;

function initCanvas()
{
    const canvas = document.getElementById('vCanvas');
    ctx = canvas.getContext("2d");
    ctx.fillStyle = "black";
    ctx.fillRect(0, 0, canvasWidth * window.devicePixelRatio, canvasHeight * window.devicePixelRatio);

    const img = new Image();
    img.addEventListener("load", () => {
         const cratio = canvas.width / canvas.height;
         const iratio = img.width / img.height;
         if (cratio > iratio) {
            var neww = canvasHeight * iratio;
            ctx.drawImage(img, 0, 0, neww, canvasHeight);
         } else {
            var newh = canvasWidth / iratio;
            ctx.drawImage(img, 0, 0, canvasWidth, newh);
         }
    });
    img.src = 'api/file/Images/virtualdisplaybackground.jpg';
    
	buffer = document.createElement('canvas');
	buffer.width = canvas.width * window.devicePixelRatio;
	buffer.height = canvas.height * window.devicePixelRatio;
	bctx = buffer.getContext('2d');

	// Draw the black pixels
	bctx.fillStyle = '#000000';
	for (var key in cellColors) {
		bctx.fillRect(cellColors[key].x, cellColors[key].y, 1, 1);
	}
}

function processEvent(e)
{
	var pixels = e.data.split('|');

	for (i = 0; i < pixels.length; i++)
	{
		// color:pixel;pixel;pixel|color:pixel|color:pixel;pixel
		var data = pixels[i].split(':');

		var rgb = data[0];

		var r = base64[rgb.substring(0,1)];
		var g = base64[rgb.substring(1,2)];
		var b = base64[rgb.substring(2,3)];

		bctx.fillStyle = '#' + r + g + b;

		// Uncomment to see the incoming color and location data in real time
		// $('#data').html(bctx.fillStyle + ' => ' + data[1] + '<br>' + $('#data').html().substring(0,500));

		var locs = data[1].split(';');
		for (j = 0; j < locs.length; j++)
		{
			var s = scaleMap[locs[j]];

			bctx.fillRect(s.x, s.y, 1, 1);
		}
	}
	ctx.drawImage(buffer, 0, 0);
}

function startSSE()
{
	evtSource = new EventSource('//<?php echo $_SERVER['HTTP_HOST'] ?>:32328/');

	evtSource.onmessage = function(event) {
		processEvent(event);
	};
}

function stopSSE()
{
	$('#stopButton').hide();

	evtSource.close();
}

function setupSSEClient()
{
	initCanvas();

	startSSE();
}

$(document).ready(function() {
	setupSSEClient();
});

</script>

<input type='button' id='stopButton' onClick='stopSSE();' value='Stop Virtual Display'><br>
<table border=0>
<tr><td valign='top'>
<canvas id='vCanvas' width='<?= $canvasWidth ?>px' height='<?= $canvasHeight ?>px'></canvas></td>
<td id='data'></td></tr></table>
