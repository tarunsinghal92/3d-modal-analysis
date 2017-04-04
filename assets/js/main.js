function draw_plot(element) {

    legend = canvasData.modal.plot.disp.legends;
    switch (element) {
        case 'floordisp':
            unit = 'Displacement (mm)';
            data = canvasData.modal.plot.disp.data;
            break;
        case 'eqdata':
            unit = 'Acceleration (m/s2)';
            data = canvasData.modal.plot.eq.data;
            break;
        case 'baseshear':
            unit = 'Force (KN)';
            data = canvasData.modal.plot.baseshear.data;
            break;
        default:
            return;
    }
    // console.log(data)

    Highcharts.chart(element, {
        title: {
            text: element,
            x: -20 //center
        },
        xAxis: {
            categories: legend
        },
        yAxis: {
            title: {
                text: unit
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
}

function clear(context, color) {
    context.clearRect(-1000, 0, canvas.width, canvas.height);
}

function change_result(obj) {
    setResult_type($(obj).attr('id'));
    draw_analysis($('#slider').slider("option", "value"));
}


function setResult_type(type) {
    switch (type) {
        case 'ex':
            result_type = ['strains', 0, [-5, 20]];
            break;
        case 'ey':
            result_type = ['strains', 1, [-200, 320]];
            break;
        case 'Yxy':
            result_type = ['strains', 2, [0, 130]];
            break;
        case 'fx':
            result_type = ['stresses', 0, [-6, 2]];
            break;
        case 'fy':
            result_type = ['stresses', 1, [-25, 5]];
            break;
        case 'Txy':
            result_type = ['stresses', 2, [0, 10]];
            break;
        default:
            result_type = ['stresses', 0, [-6, 2]];
    }
}

var result_type = ['stresses', 2, [-10, 3]];

function draw_analysis(step) {

    // clear canvas
    clear(context, '#ffffff');

    // draw base case
    for (var floor in canvasData.modal.canvas[0].floors) {
        for (var lineID in canvasData.modal.canvas[0].floors[floor]) {
            line = canvasData.modal.canvas[0].floors[floor][lineID];
            draw_line(context, line[0][0], line[0][1], line[1][0], line[1][1], 0.3, false, '');
        }
    }

    // draw shear results
    for (var floor in canvasData.shear[step]) {
        for (var fx in canvasData.shear[step][floor].elements) {
            for (var fy in canvasData.shear[step][floor].elements[fx]) {
                line = canvasData.shear[step][floor].elements[fx][fy];
                data = canvasData.shear[step][floor];
                if (result_type[0] == 'stresses') {
                    fillinstress(context, line, (data[result_type[0]][fx][fy][result_type[1]]));
                } else {
                    fillinstrain(context, line, (data[result_type[0]][fx][fy][result_type[1]]));
                }
                if (data.cracks[fx][fy].iscracked == 1) {
                    draw_line3(context, data.cracks[fx][fy].pos1[0], data.cracks[fx][fy].pos1[1], data.cracks[fx][fy].pos2[0], data.cracks[fx][fy].pos2[1], data.cracks[fx][fy].dwidth); //crack
                }
                draw_line2(context, line[0][0], line[0][1], line[1][0], line[1][1], 0.8);
                draw_line2(context, line[1][0], line[1][1], line[2][0], line[2][1], 0.8);
                draw_line2(context, line[2][0], line[2][1], line[3][0], line[3][1], 0.8);
                draw_line2(context, line[3][0], line[3][1], line[0][0], line[0][1], 0.8);
            }
        }
    }

    // draw modified columns
    for (var floor in canvasData.modal.canvas[step].floors) {
        for (var lineID in canvasData.modal.canvas[step].floors[floor]) {
            line = canvasData.modal.canvas[step].floors[floor][lineID];
            draw_line(context, line[0][0], line[0][1], line[1][0], line[1][1], 0.8, false, '');
        }
    }
}
var stmax = [];
var ssmax = [];

function fillinstrain(context, line, number) {

    //as per vector 2
    number = number * 100000;
    number = 100 * ((number - result_type[2][0]) / (result_type[2][1] - result_type[2][0]));
    number = Math.min(number, Math.max(number, 0));
    if (number <= 25) {
        // dark blue to purple
        r = Math.floor(255 * (number / 25));
        g = 0;
        b = 255;

    } else if (number > 25 && number <= 50) {
        // purple to red
        r = 255;
        g = 0;
        b = Math.floor(255 * ((50 - number) / 25));

    } else if (number > 50 && number <= 75) {
        // red to brown
        r = 255;
        g = Math.floor(255 * ((number - 50) / 25));
        b = 0;

    } else {
        // brown to green
        r = Math.floor(255 * ((100 - number) / 25));
        g = 255;
        b = 0;
    }
    context.beginPath();
    context.moveTo(line[0][0], line[0][1]);
    context.lineTo(line[1][0], line[1][1]);
    context.lineTo(line[2][0], line[2][1]);
    context.lineTo(line[3][0], line[3][1]);
    context.closePath();
    context.fillStyle = "rgb(" + r + "," + g + "," + b + ")";
    context.fill();
}

function fillinstress(context, line, number) {

    //as per vector 2
    number = 100 * ((number - result_type[2][0]) / (result_type[2][1] - result_type[2][0]));
    number = Math.min(number, Math.max(number, 0));
    if (number <= 25) {
        // dark blue to purple
        r = Math.floor(255 * (number / 25));
        g = 0;
        b = 255;

    } else if (number > 25 && number <= 50) {
        // purple to red
        r = 255;
        g = 0;
        b = Math.floor(255 * ((50 - number) / 25));

    } else if (number > 50 && number <= 75) {
        // red to brown
        r = 255;
        g = Math.floor(255 * ((number - 50) / 25));
        b = 0;

    } else {
        // brown to green
        r = Math.floor(255 * ((100 - number) / 25));
        g = 255;
        b = 0;
    }
    context.beginPath();
    context.moveTo(line[0][0], line[0][1]);
    context.lineTo(line[1][0], line[1][1]);
    context.lineTo(line[2][0], line[2][1]);
    context.lineTo(line[3][0], line[3][1]);
    context.closePath();
    context.fillStyle = "rgb(" + r + "," + g + "," + b + ")";
    context.fill();
}

function fillinstressbkp2(context, line, number) {
    // abaqus gradient
    number = Math.min(number, 100)
    if (number <= 25) {
        // dark blue to light blue
        r = 0;
        g = Math.floor(255 * (number / 25));
        b = 255;

    } else if (number > 25 && number <= 50) {
        // blue to green
        r = 0;
        g = 255;
        b = Math.floor(255 * ((50 - number) / 25));

    } else if (number > 50 && number <= 75) {
        // green to yellow
        r = Math.floor(255 * ((number - 50) / 25));
        g = 255;
        b = 0;

    } else {
        // yellow to red
        r = 255;
        g = Math.floor(255 * ((100 - number) / 25));
        b = 0;
    }
    context.beginPath();
    context.moveTo(line[0][0], line[0][1]);
    context.lineTo(line[1][0], line[1][1]);
    context.lineTo(line[2][0], line[2][1]);
    context.lineTo(line[3][0], line[3][1]);
    context.closePath();
    context.fillStyle = "rgb(" + r + "," + g + "," + b + ")";
    context.fill();
}


function draw_line3(context, posx1, posy1, posx2, posy2, width) {
    context.beginPath();
    context.moveTo(posx1, posy1);
    context.lineTo(posx2, posy2);
    context.globalAlpha = 0.8;
    context.lineWidth = width; // change to it actual width @todo
    context.stroke();
}


function draw_line2(context, posx1, posy1, posx2, posy2, alpha) {
    context.beginPath();
    context.moveTo(posx1, posy1);
    context.lineTo(posx2, posy2);
    context.globalAlpha = alpha;
    context.lineWidth = 1;
    context.stroke();
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



// The Tool-Tip instance:
function ToolTip(canvas, region, text, width, timeout) {
    var me = this, // self-reference for event handlers
        div = document.createElement("div"), // the tool-tip div
        parent = canvas.parentNode, // parent node for canvas
        visible = false; // current status

    // set some initial styles, can be replaced by class-name etc.
    div.style.cssText = "position:fixed;padding:7px;background:gold;pointer-events:none;width:" + width + "px";
    div.innerHTML = text;

    // show the tool-tip
    this.show = function(pos) {
        if (true) { // ignore if already shown (or reset time)
            visible = true; // lock so it's only shown once
            setDivPos(pos); // set position
            parent.appendChild(div); // add to parent of canvas
            setTimeout(hide, timeout); // timeout for hide
        }
    }

    // hide the tool-tip
    function hide() {
        visible = false; // hide it after timeout
        parent.removeChild(div); // remove from DOM
    }

    // check mouse position, add limits as wanted... just for example:
    // function check(e) {
    //     var pos = getPos(e),
    //         posAbs = {
    //             x: e.clientX,
    //             y: e.clientY
    //         }; // div is fixed, so use clientX/Y
    //     if (!visible && get_total_angle_produced(region, pos.x, pos.y)) {
    //         me.show(posAbs); // show tool-tip at this pos
    //     } else setDivPos(posAbs); // otherwise, update position
    // }

    // check mouse position, add limits as wanted... just for example:
    function check(e) {
        var pos = getPos(e),
            posAbs = {
                x: e.clientX,
                y: e.clientY
            }; // div is fixed, so use clientX/Y
        if (true &&
            pos.x >= 0.5 * (region.x1 + region.x4) && pos.x < 0.5 * (region.x2 + region.x3) &&
            pos.y >= 0.5 * (region.y1 + region.y4) && pos.x < 0.5 * (region.y2 + region.y3)) {
            me.show(posAbs); // show tool-tip at this pos
        } else setDivPos(posAbs); // otherwise, update position
    }

    // get total angle
    function get_total_angle_produced(pos, px, py) {
        a1 = get_angle(pos.x1, pos.y1, pos.x2, pos.y2, px, py);
        a2 = get_angle(pos.x2, pos.y2, pos.x3, pos.y3, px, py);
        a3 = get_angle(pos.x3, pos.y3, pos.x4, pos.y4, px, py);
        a4 = get_angle(pos.x4, pos.y4, pos.x1, pos.y1, px, py);
        total = parseInt(toDegrees(a1 + a2 + a3 + a4));
        // console.log(total)
        if (total > 345 && total < 365) {
            return true;
        }
        return false;
    }

    // to degree
    function toDegrees(angle) {
        return angle * (180 / Math.PI);
    }

    // get angle between lines
    function get_angle(x1, y1, x2, y2, px, py) {
        a = get_dist(x1, y1, px, py);
        b = get_dist(x1, y1, px, py);
        c = get_dist(x1, y1, x2, y2);
        return Math.acos((c ** 2 + b ** 2 - a ** 2) / (2 * c * b));
    }

    // distance b/w points
    function get_dist(x1, y1, x2, y2) {
        return Math.sqrt((x1 - x2) ** 2 + (y1 - y2) ** 2);
    }

    // get mouse position relative to canvas
    function getPos(e) {
        var r = canvas.getBoundingClientRect();
        return {
            x: e.clientX - r.left,
            y: e.clientY - r.top
        }
    }

    // update and adjust div position if needed (anchor to a different corner etc.)
    function setDivPos(pos) {
        if (visible) {
            if (pos.x < 0) pos.x = 0;
            if (pos.y < 0) pos.y = 0;
            // other bound checks here
            div.style.left = pos.x + "px";
            div.style.top = pos.y + "px";
        }
    }

    // we need to use shared event handlers:
    // canvas.addEventListener("mousemove", check);
    canvas.addEventListener("click", check);

}

function draw_analysis2() {

    // clear canvas
    clear(context, '#ffffff');
    data = canvasData;

    //get canvas element
    for (var fx in canvasData.elements) {
        for (var fy in canvasData.elements[fx]) {
            line = canvasData.elements[fx][fy];
            if (result_type[0] == 'stresses') {
                fillinstress(context, line, (data[result_type[0]][fx][fy][result_type[1]]));
            } else {
                fillinstrain(context, line, (data[result_type[0]][fx][fy][result_type[1]]));
            }
            if (data.cracks[fx][fy].iscracked == 1) {
                draw_line3(context, data.cracks[fx][fy].pos1[0], data.cracks[fx][fy].pos1[1], data.cracks[fx][fy].pos2[0], data.cracks[fx][fy].pos2[1], data.cracks[fx][fy].dwidth); //crack
            }
            draw_line2(context, line[0][0], line[0][1], line[1][0], line[1][1], 0.8);
            draw_line2(context, line[1][0], line[1][1], line[2][0], line[2][1], 0.8);
            draw_line2(context, line[2][0], line[2][1], line[3][0], line[3][1], 0.8);
            draw_line2(context, line[3][0], line[3][1], line[0][0], line[0][1], 0.8);
        }
    }
}
