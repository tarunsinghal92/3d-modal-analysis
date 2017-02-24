function draw_plot(element, legends, data) {
    $(function() {
        Highcharts.chart(element, {
            title: {
                text: 'Floor Displacement',
                x: -20 //center
            },
            xAxis: {
                categories: legends
            },
            yAxis: {
                title: {
                    text: 'Displacement (mm)'
                },
                plotLines: [{
                    value: 0,
                    width: 1,
                    color: '#808080'
                }]
            },
            tooltip: {
                valueSuffix: '°C'
            },
            legend: {
                layout: 'vertical',
                align: 'right',
                verticalAlign: 'middle',
                borderWidth: 0
            },
            series: data
                // series: [JSON.parse('<?php echo json_encode($data[1]); ?>')]
        });
    });
}

var canvasData;
var context;
var canvas;

function setup_canvas(data) {

    canvas = document.getElementById('myCanvas');
    context = canvas.getContext('2d');
    canvasData = data;
    context.save();
    context.translate(600, 600);
    context.scale(1, -1);
    draw_analysis(0);
}

function clear(context, color) {
    context.clearRect(-1000, 0, canvas.width, canvas.height);
}

function draw_analysis(step) {

    //clear canvas
    clear(context, '#ffffff');

    //get canvas element
    for (var floor in canvasData[0].floors) {
        for (var lineID in canvasData[0].floors[floor]) {
            line = canvasData[0].floors[floor][lineID];
            draw_line(context, line[0][0], line[0][1], line[1][0], line[1][1], 0.3, false, '');
        }
    }

    for (var floor in canvasData[step].floors) {
        for (var lineID in canvasData[step].floors[floor]) {
            line = canvasData[step].floors[floor][lineID];
            draw_line(context, line[0][0], line[0][1], line[1][0], line[1][1], 0.8, false, '');
        }
    }

}

function draw_truss(nodes, elements, legend, alpha) {

    //unseerialize
    nodes = JSON.parse(nodes);
    elements = JSON.parse(elements);
    legend = JSON.parse(legend);

    //get canvas element
    var canvas = document.getElementById('canvas');
    var context = canvas.getContext('2d');
    context.save();
    context.translate(400, 800);
    context.scale(1, -1);

    //draw actual
    for (var n in nodes) {
        draw_point(context, nodes[n].posx, nodes[n].posy, alpha);
    }
    for (var e in elements) {
        draw_line(context, elements[e].posx1, elements[e].posy1, elements[e].posx2, elements[e].posy2, alpha, false, '');
    }

    //draw modified
    for (var n in nodes) {
        draw_point(context, nodes[n].mposx, nodes[n].mposy, 1);
    }
    for (var e in elements) {
        draw_line(context, elements[e].mposx1, elements[e].mposy1, elements[e].mposx2, elements[e].mposy2, 1, true, elements[e]);
    }

    //save canvas
    context.save();

    //draw legend
    context.scale(1, -1);
    for (var l in legend) {

        context.fillStyle = (legend[l].color);
        context.fillRect((1000), (-400 - l * 20), 50, 20);
        context.font = "12px Arial";
        context.fillStyle = 'black';
        context.fillText(legend[l].startval + ' klbs to ' + legend[l].endval + ' klbs', 1060, (-385 - l * 20));
    }
}


function draw_point(context, posx, posy, alpha) {
    context.beginPath();
    context.arc(posx, posy, 5, 0, 2 * Math.PI, false);
    context.globalAlpha = alpha;
    context.fill();
}

function draw_line(context, posx1, posy1, posx2, posy2, alpha, type, ele) {
    context.beginPath();
    context.moveTo(posx1, posy1);
    context.lineTo(posx2, posy2);
    if (type) {
        context.strokeStyle = ele.color;
    }
    context.globalAlpha = alpha;
    context.lineWidth = 3;
    context.stroke();
}
