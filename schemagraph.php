<?php
/**
 * SchemaGraph: Visualizing MySQL Databases in SVG (version 1.0)
 *   by Weston Ruter <http://weston.ruter.net/>
 *      Shepherd Interactive <http://www.shepherd-interactive.com/>
 * License: GNU GPL 2.0 <http://creativecommons.org/licenses/GPL/2.0/>
 * Inspiried by Schemaball by Martin Krzywinski <http://mkweb.bcgsc.ca/schemaball/>
 *  
 * Home page: http://weston.ruter.net/projects/schemagraph/
 * Source: http://shepherd-interactive.googlecode.com/svn/trunk/schemagraph/
 *
 * SchemaGraph, like Schemaball, queries the MySQL information_schema database to get all of the necessary
 * information about a database's (InnoDB) tables to construct a graph; this graph is then output as an interactive
 * SVG image with the following features:
 * 
 *  - Tables in the schema are placed equidistantly around a circle.
 *  - Clicking the image causes the graph to rotate.
 *  - Foreign key constraints are represented by bézier curves connecting table labels.
 *  - Hovering over a table or a constraint causes the table's label to highlight along with all of its constraint paths (both incoming and outgoing).
 *  - The paths representing incoming foreign key constraints are highlighted in a different color than outgoing constraints.
 *  - Multiple constraints between the same two tables are prevented from overlapping by giving a unique curve to each of the lines.
 *  - Hovering over a constraint produces a tooltip which shows the names of the fields that are linked by the constraint.
 *
 * To use this tool, simply place schemagraph.php on a web server and provide values
 * for the constants needed to establish the database connection. You may also modify the constants
 * for the circle radius, font size, and image dimensions. Since the script outputs plain SVG with inline
 * CSS and JavaScript, you may further customize it as needed to get the desired style or behavior.
 * 
 * @todo We shoud be able to lay the @title contents also on each of the paths
 * @todo For self-referential foreign keys, we should make them circle back instead of just be a line
 * @todo It would be great to group nodes together based on the number of constraints between them
 * @todo Size of the size of the circle next to each table name should reflect the number of entries in each table
 */

define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', @stripslashes($_GET['db'])); //change this if you only want one database to be graphable
define('RADIUS', 250); //px radius of the circle
define('FONT_SIZE', RADIUS/10); //px
define('SVG_WIDTH', RADIUS*4);
define('SVG_HEIGHT', RADIUS*4);

/**** End configuration constants; modify below only if you want to change the SVG, CSS, or JavaScript ****/

@mysql_connect(DB_HOST, DB_USER, DB_PASS);
if(mysql_errno())
	trigger_error(mysql_error(), E_USER_ERROR);
@mysql_selectdb('INFORMATION_SCHEMA');
if(mysql_errno())
	trigger_error(mysql_error(), E_USER_ERROR);

//SQL which queries the INFORMATION_SCHEMA to get all of the tables and their interrelationships
$db = mysql_real_escape_string(DB_NAME);
$sql = <<<SQL
SELECT
	c.TABLE_NAME,
	c.COLUMN_NAME,
	
	kcu.CONSTRAINT_NAME,
	kcu.REFERENCED_TABLE_NAME,
	kcu.REFERENCED_COLUMN_NAME,
	
	tc.CONSTRAINT_TYPE,
	c.COLUMN_COMMENT
FROM
	COLUMNS c
	LEFT JOIN (
		KEY_COLUMN_USAGE kcu,
		TABLE_CONSTRAINTS tc
	)
	ON (
		kcu.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_SCHEMA  = c.TABLE_SCHEMA AND
		kcu.CONSTRAINT_NAME   = tc.CONSTRAINT_NAME AND
		kcu.TABLE_NAME        = tc.TABLE_NAME AND
		c.TABLE_NAME          = kcu.TABLE_NAME AND
		c.COLUMN_NAME         = kcu.COLUMN_NAME
	)
WHERE
	c.TABLE_SCHEMA = "$db"
ORDER BY
	c.TABLE_NAME,
	c.ORDINAL_POSITION;
SQL;

//Iterate over all of the results to build the data structure
$links = array();
$fkTotal = 0;
$q = @mysql_query($sql);
if(mysql_errno())
	trigger_error(mysql_error(), E_USER_ERROR);
while($row = mysql_fetch_object($q)){
	if(@!$links[$row->TABLE_NAME]){
		$links[$row->TABLE_NAME] = new stdClass();
		$links[$row->TABLE_NAME]->in = array();
		$links[$row->TABLE_NAME]->out = array();
	}
	
	if($row->CONSTRAINT_TYPE == 'FOREIGN KEY'){
		$fkTotal++;
		$fkLink = array(
			'to_table'     => $row->REFERENCED_TABLE_NAME,
			'to_column'    => $row->REFERENCED_COLUMN_NAME,
			'from_table'   => $row->TABLE_NAME,
			'from_column'  => $row->COLUMN_NAME
		);
		$links[$row->TABLE_NAME]->out[$row->CONSTRAINT_NAME] = &$fkLink;
		
		if(@!$links[$row->REFERENCED_TABLE_NAME]){
			$links[$row->REFERENCED_TABLE_NAME] = new stdClass();
			$links[$row->REFERENCED_TABLE_NAME]->in = array();
			$links[$row->REFERENCED_TABLE_NAME]->out = array();
		}
		
		$links[$row->REFERENCED_TABLE_NAME]->in[$row->CONSTRAINT_NAME] = &$fkLink;
		
		unset($fkLink);
	}
}
if(!count($links))
	trigger_error("No database named '$db' exists or there are no tables in it", E_USER_ERROR);
$tables = array_keys($links);
sort($tables);
$total = count($links);

@header('content-type:image/svg+xml');
echo '<?'.'xml version="1.0"'."?>\n";
?>
<!--
SchemaGraph: Visualizing MySQL Databases in SVG
by Weston Ruter <http://weston.ruter.net/>
   Shepherd Interactive <http://www.shepherd-interactive.com/>
License: GNU GPL 2.0 <http://creativecommons.org/licenses/GPL/2.0/>
Inspiried by Schemaball by Martin Krzywinski <http://mkweb.bcgsc.ca/schemaball/>
 
Home page: http://weston.ruter.net/projects/schemagraph/
Source: http://shepherd-interactive.googlecode.com/svn/trunk/schemagraph/
-->
<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" viewBox="<?php
	echo -SVG_WIDTH/2 . " ";
	echo -SVG_HEIGHT/2 . " ";
	echo SVG_WIDTH . " ";
	echo SVG_HEIGHT;
?>">
<!--
There are <?php echo count($tables) ?> tables, with <?php echo $fkTotal ?> foreign keys.
	
Tables:
<?php echo ' - ' . join("\n - ", $tables) . "\n"; ?>
-->
	<title>SchemaGraph of <?php echo DB_NAME ?></title>
	<style type="text/css">
		text {
			font-family:Arial,Helvetica,sans-serif;
			font-size:<?php echo FONT_SIZE ?>px;
		}
		#tables circle {
			fill:red;
		}
		#tables path {
			stroke:#bbb;
			opacity:0.3;
			stroke-width:2px;
			fill:none;
		}
		#tables g:hover path,
		#tables g.hover path {
			stroke:blue;
			stroke-width:4px;
			opacity:0.6;
		}
		#tables path.incoming {
			stroke:lime;
			stroke-width:3px;
			opacity:0.6;
		}
		g#tables g:hover text,
		g#tables g.hover text {
			font-weight:bold;
		}
		g#tables g:hover circle,
		g#tables g.hover circle {
			fill:purple;
			opacity:1.0;
		}
	</style>
	<circle r="<?php echo RADIUS ?>" fill="none" stroke="black" stroke-width="2px" />

	<g id="tables" transform="rotate(90)">
		<?php
		$pos = 0;
		$tableCoordinates = array();
		foreach($tables as $table){
			$angle = (360/$total)*$pos;
			$y = 0;
			$x = RADIUS;
			
			$tableCoordinates[$table] = new stdClass();
			$tableCoordinates[$table]->x = $x*cos(deg2rad($angle)) - $y*sin(deg2rad($angle));
			$tableCoordinates[$table]->y = $x*sin(deg2rad($angle)) + $y*cos(deg2rad($angle));
			$tableCoordinates[$table]->rotation = ((pi()*2)/$total)*$pos;
			
			$pos++;
		}
		
		$pos = 0;
		$usedPaths = array();
		
		foreach($tables as $table): ?>
			<?php
			$constraints = &$links[$table];
			?>
			<g id="<?php echo $table ?>" class="<?php
				foreach(array_keys($constraints->in) as $n)
					echo "$n ";
			?>">
				<?php
				$x1 = $tableCoordinates[$table]->x;
				$y1 = $tableCoordinates[$table]->y;
				$rotation1 = $tableCoordinates[$table]->rotation;
				
				?>
				<circle id="c_<?php echo $table ?>" cx="<?php echo $x1 ?>" cy="<?php echo $y1 ?>" r="6" />
				<text x="<?php echo RADIUS + 15 ?>" dy="<?php echo FONT_SIZE/4 ?>" transform="rotate(<?php echo rad2deg($rotation1) ?>)"><?php echo $table ?></text>
				<?php
				foreach($constraints->out as $name => $fkLink){
					$x2 = $tableCoordinates[$fkLink['to_table']]->x;
					$y2 = $tableCoordinates[$fkLink['to_table']]->y;
					$rotation2 = $tableCoordinates[$fkLink['to_table']]->rotation;
					$distanceBetweenPoints = sqrt(pow($x2 - $x1, 2) + pow($y2 - $y1, 2));
					
					//Using the law of cosines, we can determine the angle between the two points from the origin
					$angle = acos((2 * pow(RADIUS, 2) - pow($distanceBetweenPoints, 2))/(2 * RADIUS * RADIUS));
					
					//Self-referential (should loop?)
					if($x1 == $x2 && $y1 == $y2){
						$path = "M $x1 $y1 S 0 0 $x2 $y2";
						//$m = ($y1 + ($y2 - $y1)/2) / ($x1 + ($x2 - $x1)/2);
						//$x = 0;
						//do {
						//	$y = $m * $x;
						//	$path = "M $x1 $y1 S $x $y $x2 $y2";
						//	$x += 30;
						//}
						//while(isset($usedPaths[$path]));
						//$usedPaths[$path] = true;
					}
					//Other-referential
					else {
						$step = 0;
						$x = $y = -1;
						do {
							//Prevent a line from completely overlapping another 
							//  If the target point appears in the following hemisphere, then add the angle to the current angle
							//  otherwise if the target point appers in the preceding hemisphere, then subtract the angle
							$diff = $rotation2 - $rotation1;
							if($diff < 0)
								$diff += 2*pi();
							
							if($diff < pi()){
								$y = sin($rotation1 + $angle/2)*$step;
								$x = cos($rotation1 + $angle/2)*$step;
							}
							else {
								$y = sin($rotation1 - $angle/2)*$step;
								$x = cos($rotation1 - $angle/2)*$step;
							}
							
							$path = "M $x1 $y1 Q $x $y $x2 $y2";
							$step += RADIUS/14;
						}
						while(isset($usedPaths[$path]) || isset($usedPaths["M $x2 $y2 Q $x $y $x1 $y1"]));
						$usedPaths[$path] = true;
					}
					
					echo "<path id='$name' d='$path' "
						     . "title='$fkLink[from_table].$fkLink[from_column] ➡ $fkLink[to_table].$fkLink[to_column]'"
						     . " />";
				}
				?>
			</g>
			<?php $pos++; ?>
		<?php endforeach; ?>
	</g>

	<script type="text/ecmascript" xlink:href="http://ajax.googleapis.com/ajax/libs/jquery/1.2.6/jquery.min.js" />
	<script type="text/ecmascript">
	<![CDATA[
	
	//Highlight incoming foreign key dependencies when hovering over a table
	var constraints;
	var selectedTable;
	$('#tables g').mouseover(function(e){
		var tokenlist = this.getAttribute('class').replace(/^\s*|\s*$/g, '');
		if(!tokenlist){
			constraints = [];
			return;
		}
		
		constraints = tokenlist.split(/\s+/);
		$(constraints).each(function(){
			var path = document.getElementById(this);
			if(path)
				path.setAttribute('class', 'incoming');
		});
		selectedTable = this;
		
	}).mouseout(function(e){
		selectedTable = null;
		
		$(constraints).each(function(){
			var el = document.getElementById(this);
			if(el)
				el.setAttribute('class', '');
		});
	});
	
	var tables = document.getElementById('tables');
	var rotate = parseInt(tables.getAttribute('transform').match(/rotate\((-?\d+(?:.\d+)?)\)/)[1], 10);
	
	//Rotate the graph when it is clicked
	$(document.documentElement).click(function(){
		rotate += 10;
		tables.transform.baseVal.getItem(0).setRotate(rotate, 0, 0);
	});
	
	]]>
	</script>
</svg>
