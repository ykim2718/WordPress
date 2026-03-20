/* Y, 2026.3.5 - 6, 3.10 - 20
 * AJAX & Plotly.react Version
 */
(function() {
    // 1. 중복 실행 방지
    if (window.__MONGO_PLOTLY_CHART_RUNNING__) {
        console.warn("⚠️ 이미 MongoDB Plotly 스크립트가 실행 중입니다.");
        return;
    } else {
		window.__MONGO_PLOTLY_CHART_RUNNING__ = true;
		console.warn("🎃 MongoDB Plotly 스크립트를 실행합니다.");
	}

    let readinessRetryCount = 0; 
    const checkReadinessCount = 10;
    const checkReadinessInterval = 1000;  // 1000 msec
    let refreshTimer = null;
	let refreshCount = 0;
    let activeInstanceId = null;  // 전역 변수 의존성을 줄이기 위해 instanceId를 추적

    async function fetchDataAndRender(instanceId) {
        const chartVars = window.mongoChartVars[instanceId];
        if (!chartVars) {
			console.error(`❌ [${instanceId}] 데이터를 찾을 수 없어 갱신을 중단합니다.`);
			return;
		}
		if (!chartVars.ajax_url) { /* 안전장치 */
            console.error("❌ 오류: ajax_url이 정의되지 않았습니다.");
            return;
        }
        
        const container = document.getElementById(chartVars.chart_canvas_id);
        const chartStatusTag = document.getElementById(chartVars.chart_status_id);

        try {
            //const response = await fetch(chartVars.ajax_url);
			const cacheBuster = chartVars.ajax_url.includes('?') ? '&' : '?';
            const response = await fetch(`${chartVars.ajax_url}${cacheBuster}_t=${Date.now()}`);  // Cache Busting
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const newData = await response.json();
            if (newData && newData.data) {
                renderWithReact(container, newData);
				refreshCount++;
                if (chartStatusTag) {
					chartStatusTag.innerText = `✅ Update #${refreshCount}: ${new Date().toLocaleTimeString('en-US')}`;
               }
            }
        } catch (error) {
            console.error("Fetch 에러:", error);
        } finally {
            // setInterval 대신, 전역 변수인 refreshTimer를 활용

            // 1. 간격 계산 (초 단위 * 1000)
            const rawInterval = chartVars.refresh_interval;  // sec
			const nextInterval = parseInt(chartVars.refresh_interval) * 1000 || 60000;  // msec
			
			// 2. 정확한 다음 실행 시각 계산
            const now = new Date();
            const nextRunTime = new Date(now.getTime() + nextInterval);
            const timeString = nextRunTime.toLocaleTimeString('en-US', { hour12: false });
			
			// 3. 기존 타이머 제거
            if (window.mongoRefreshTimer) clearTimeout(window.mongoRefreshTimer);  // 중복 예약 방지
            
			// 4. 다음 갱신 예약
			window.mongoRefreshTimer = setTimeout(() => {
				console.log(`🔥 [${instanceId}] ${refreshCount + 1}번째 갱신! (예약시각 ${timeString})`);
                fetchDataAndRender(instanceId); 
            }, nextInterval);
			
			// 5. 로그 출력 (정확한 시각 포함)
			console.log(`⏱️ [${instanceId}] #${refreshCount} 완료. 다음 갱신은 ${rawInterval}초 후인 ${timeString} 입니다.`);
        }
    }

    function renderWithReact(container, vars) {
        const initialData = vars.data || vars.chart_data || [];
        const tracesMap = {};

        initialData.forEach((item) => {
            if (!item || !item._id || !item._id.time) return;
            let code = (item._id && item._id.code) ? item._id['code'] : "Unknown";
            
            if (!tracesMap[code]) {
                tracesMap[code] = {
                    x: [], y: [], name: code,
                    type: 'scatter', mode: 'lines+markers',
                    marker: { size: 4, symbol: 'circle' },
                    line: { width: 1.5 },
                    connectgaps: true
                };
            }
            
            if (tracesMap[code] && Array.isArray(tracesMap[code].x)) {
                tracesMap[code].x.push(new Date(item._id.time));
                tracesMap[code].y.push(Number(item.close || 0));
            }
        });

        const finalData = Object.values(tracesMap);
        const layout = {
            margin: { t: 40, b: 40, l: 60, r: 40 },
            hovermode: 'x unified',
            xaxis: { type: 'date', rangeslider: { visible: true, thickness: 0.05 } },
            yaxis: { autorange: true, tickformat: ',d' },
            showlegend: true
        };
		const config = {
            responsive: true,
            displaylogo: false,
            modeBarButtonsToRemove: [
                'select2d',      // Box Select (박스 선택)
                'lasso2d',       // Lasso Select (올가미 선택)
                //'zoomIn2d',      // 확대
                //'zoomOut2d',     // 축소
                //'autoScale2d'    // 자동 스케일
            ]
        };

        Plotly.react(container, finalData, layout, config);  // Plotly.react는 기존 차트를 재사용하여 변경된 부분만 업데이트합니다.
    }

    function startAutoRefresh(instanceId) {
		console.log(`🚀 [${instanceId}] 자동 차트 시작.`);
        fetchDataAndRender(instanceId);
    }

    function init() {
		if (readinessRetryCount == 0) {
			console.log("🚩 MONGO_PLOTLY_CHART loading first ...");
		}
		
        // 1. 데이터가 있는지 매 시도마다 확인
        let chartVars = null;
        if (window.mongoChartVars && Object.keys(window.mongoChartVars).length > 0) {
            activeInstanceId = Object.keys(window.mongoChartVars)[0];  // 현재 등록된 첫 번째 ID를 찾음
            chartVars = window.mongoChartVars[activeInstanceId];
        }
		
		const plotlyLoaded = typeof Plotly !== "undefined";
        const container = chartVars ? document.getElementById(chartVars.chart_canvas_id) : null;
        const chartStatusTag = chartVars ? document.getElementById(chartVars.chart_status_id) : null;

        // 2. 실행 조건 확인
        if (plotlyLoaded && chartVars && container) {
            console.log(`✅ [${activeInstanceId}] 로딩 완료.`);
            startAutoRefresh(activeInstanceId);
            return;
        }


        // 3. 재시도
        if (readinessRetryCount < checkReadinessCount) {
            readinessRetryCount++;
            const message = `⏳ Waiting for the next try to load ... (${readinessRetryCount}/${checkReadinessCount})`;
			console.error(message);
            if (chartStatusTag) {
                chartStatusTag.innerText = message;
                chartStatusTag.style.color = "orange";
            }
            setTimeout(init, checkReadinessInterval);
        } else {
            const errorMessage = "❌ 로딩 실패: 데이터 또는 라이브러리를 찾을 수 없습니다.";
            console.error(errorMessage);
			console.error(`❌ 데이터 (window.mongoChartVars): ${window.mongoChartVars}`);
			console.error(`❌ 라이브러리 (Plotly.version): ${Plotly.version}`);
            if (chartStatusTag) {
                chartStatusTag.innerText = errorMessage;
                chartStatusTag.style.color = "red";
            }
        }
    }

    init();
})();