/**
 * VoucherReset - Animated 3D network background.
 * Nodes drift through pseudo-3D space; nearer ones are larger/brighter,
 * and neighbours are linked with depth-based opacity. Self-contained, no libraries.
 */
(function () {
    'use strict';
    var canvas = document.getElementById('net-bg');
    if (!canvas || !canvas.getContext) return;
    var ctx = canvas.getContext('2d');
    var W, H, DPR, nodes = [], COUNT, FOCAL = 320;

    function resize() {
        DPR = Math.min(window.devicePixelRatio || 1, 2);
        W = window.innerWidth; H = window.innerHeight;
        canvas.width = W * DPR; canvas.height = H * DPR;
        canvas.style.width = W + 'px'; canvas.style.height = H + 'px';
        ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
        COUNT = Math.max(40, Math.min(100, Math.floor((W * H) / 17000)));
        build();
    }

    function build() {
        nodes = [];
        for (var i = 0; i < COUNT; i++) {
            nodes.push({
                x: (Math.random() - 0.5) * W * 1.4,
                y: (Math.random() - 0.5) * H * 1.4,
                z: Math.random() * 600 + 60,
                vx: (Math.random() - 0.5) * 0.25,
                vy: (Math.random() - 0.5) * 0.25,
                vz: (Math.random() - 0.5) * 0.35
            });
        }
    }

    function project(n) {
        var s = FOCAL / (FOCAL + n.z);
        return { sx: W / 2 + n.x * s, sy: H / 2 + n.y * s, s: s };
    }

    function frame() {
        ctx.clearRect(0, 0, W, H);
        var p = [];
        for (var i = 0; i < nodes.length; i++) {
            var n = nodes[i];
            n.x += n.vx; n.y += n.vy; n.z += n.vz;
            if (n.z < 40) { n.z = 660; }
            if (n.z > 660) { n.z = 40; }
            if (n.x < -W * 0.8 || n.x > W * 0.8) n.vx *= -1;
            if (n.y < -H * 0.8 || n.y > H * 0.8) n.vy *= -1;
            p.push(project(n));
        }
        for (var a = 0; a < p.length; a++) {
            for (var b = a + 1; b < p.length; b++) {
                var dx = p[a].sx - p[b].sx, dy = p[a].sy - p[b].sy;
                var d = Math.sqrt(dx * dx + dy * dy);
                if (d < 165) {
                    var o = (1 - d / 165) * 0.8 * Math.min(p[a].s, p[b].s);
                    ctx.strokeStyle = 'rgba(140,130,255,' + o.toFixed(3) + ')';
                    ctx.lineWidth = 0.85;
                    ctx.beginPath();
                    ctx.moveTo(p[a].sx, p[a].sy);
                    ctx.lineTo(p[b].sx, p[b].sy);
                    ctx.stroke();
                }
            }
        }
        for (var k = 0; k < p.length; k++) {
            var r = 2.4 * p[k].s + 0.3;
            var glow = 0.5 + 0.5 * p[k].s;
            ctx.beginPath();
            ctx.arc(p[k].sx, p[k].sy, r, 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(165,170,255,' + (glow * 0.95).toFixed(3) + ')';
            ctx.shadowColor = 'rgba(124,92,255,0.9)';
            ctx.shadowBlur = 8 * p[k].s;
            ctx.fill();
        }
        ctx.shadowBlur = 0;
        requestAnimationFrame(frame);
    }

    window.addEventListener('resize', resize);
    resize();
    requestAnimationFrame(frame);
})();
