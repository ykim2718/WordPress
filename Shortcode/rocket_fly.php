/* Y, 2026.3.17 - 18
 * A Rocket flying to the mouse
 * Viewport Animation, Accerlated Moving, Velocity-dependent Flame, Intertia Control
 */
add_shortcode('rocket_fly', '_rocket_fly');
function _rocket_fly() {
    $style = "
    <style>
        #rocket-master {
            position: fixed;
            bottom: 10vh;
            left: 5%;
            z-index: 99999;
            pointer-events: none;
            display: block;
            will-change: transform, left, top;
            opacity: 0;
            transition: opacity 1s ease-in;
        }
        .rocket-icon { 
            font-size: 60px; 
            position: relative;
            display: inline-block;
        }
        /* 화염: 이모지의 왼쪽 아래 구석 (꼬리)에 부착 */
        .mystic-glow {
            position: absolute;
            bottom: 27%;  /* 🚀 이모지의 꼬리 위치 정밀 조정 */
            left: 53%;  /* 🚀 이모지의 꼬리 위치 정밀 조정 */
            width: 35px;
            height: 90px;
            background: linear-gradient(to bottom, #fff, #ffeb3b, #ff9800, transparent);
            filter: blur(5px);
            border-radius: 50%;
            z-index: 10;  /* 0보다 큰 양수를 주면 로켓 위로 겹쳐 보임 */

            /* 중요: 화염의 회전 중심을 로켓과 만나는 지점(꼬리)으로 설정 */
            transform-origin: bottom left;

            /* 로켓 머리가 45도(우상향)이므로, 화염이 반대 방향으로 뿜어지도록 보정 */
            transform: rotate(45deg) scaleY(0.7); /* 초기 스케일 설정 */
            will-change: transform; /* 성능 최적화 */
        }
    </style>
    ";

    $html = '
    <div id="rocket-master">
        <div class="rocket-icon">
            🚀
            <div class="mystic-glow" id="rocket-flame"></div> </div>
    </div>
    ';

    $script = "
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var rocket = document.getElementById('rocket-master');
        var flame = document.getElementById('rocket-flame');
        var mouseX = window.innerWidth * 0.05;
        var mouseY = window.innerHeight * 0.9;
        var rocketX = mouseX;
        var rocketY = mouseY;
		var velX = 0;  // X축 속도 변수
        var velY = 0;  // Y축 속도 변수
        var lastX = rocketX; // 이전 X 좌표 저장
        var lastY = rocketY; // 이전 Y 좌표 저장
        var isLaunching = true;
        
        var currentDistance = 337;
        var MAX_DISTANCE = 337;
        var MIN_DISTANCE = 20;
        var EASE = 0.015;  // 기존 값: 0.04 (숫자가 작을수록 유영속도가 느려지고 부드러워집니다)
		var FRICTION = 0.9; // 관성 마찰 계수 (1에 가까울수록 관성이 오래 유지됨)

        setTimeout(function() {
            rocket.style.opacity = '1';
            rocket.style.bottom = 'auto';
            isLaunching = false;
        }, 100);

        var mouseTimer;
        var isMouseMoving = false;

        document.addEventListener('mousemove', function(e) {
            mouseX = e.clientX;
            mouseY = e.clientY;
            isMouseMoving = true;
            currentDistance = MAX_DISTANCE;

            clearTimeout(mouseTimer);
            mouseTimer = setTimeout(function() { isMouseMoving = false; }, 80);
        });

        function animate() {
            if (!isLaunching) {
                if (!isMouseMoving) {
                    currentDistance += (MIN_DISTANCE - currentDistance) * 0.08;
                }

                var dx = mouseX - rocketX;
                var dy = mouseY - rocketY;
                var distToMouse = Math.sqrt(dx * dx + dy * dy);
                var angle = Math.atan2(dy, dx);

                var targetX = mouseX;
                var targetY = mouseY;
                
                if (distToMouse > currentDistance) {
                    targetX = mouseX - Math.cos(angle) * currentDistance;
                    targetY = mouseY - Math.sin(angle) * currentDistance;
                }
				
				// 속도에 가속도 (EASE) 고려
                velX += (targetX - rocketX) * EASE;
                velY += (targetY - rocketY) * EASE;
				// 속도에 마찰력(FRICTION)을 곱해 관성 구현
                velX *= FRICTION;
                velY *= FRICTION;
				
				// '속도(Velocity)'를 실제 '위치(Position)'에 반영: 거리 = 속도 × 시간
                rocketX += velX;
                rocketY += velY;

                // 실시간 이동 속도 계산
                var moveDist = Math.sqrt(Math.pow(rocketX - lastX, 2) + Math.pow(rocketY - lastY, 2));
                var dynamicScale = 0.7 + (moveDist * 0.2); // 속도에 따른 스케일 계산
                if (dynamicScale > 1.8) dynamicScale = 1.8; // 최대 길이 제한

                // 로켓 머리 각도 보정 (45도 기울어진 아이콘 대응)
                //var rotateAngle = (angle * 180 / Math.PI) + 45;
				// 방향 전환이 너무 민감하게 변하지 않도록 속도가 있을 때만 각도 업데이트
                if (moveDist > 2) {  // 2 px
                    var rotateAngle = (Math.atan2(velY, velX) * 180 / Math.PI) + 45;
                    rocket.style.transform = 'translate(-50%, -50%) rotate(' + rotateAngle + 'deg)';
                }

                rocket.style.left = rocketX + 'px';
                rocket.style.top = rocketY + 'px';
                rocket.style.transform = 'translate(-50%, -50%) rotate(' + rotateAngle + 'deg)';
                
                // 화염에 동적 스케일 적용 (CSS 애니메이션 대체)
                flame.style.transform = 'rotate(225deg) scaleY(' + dynamicScale + ')';

                lastX = rocketX; // 현재 위치 업데이트
                lastY = rocketY; // 현재 위치 업데이트
            }
            requestAnimationFrame(animate);
        }
        animate();

        document.addEventListener('keydown', function() {
            rocket.style.transition = 'opacity 0.4s';
            rocket.style.opacity = '0';
            setTimeout(function() { rocket.style.display = 'none'; }, 400);
        });
    });
    </script>
    ";

    return $style . $html . $script;
}