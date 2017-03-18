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
