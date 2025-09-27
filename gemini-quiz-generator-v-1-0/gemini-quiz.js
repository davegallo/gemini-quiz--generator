document.addEventListener('DOMContentLoaded', function () {
    const containers = document.querySelectorAll('.gemini-quiz-container');
    containers.forEach((container, index) => {
        const uniqueId = `gemini_quiz_${index + 1}`;
        container.id = uniqueId;

        const quizState = {
            state: 'config',
            questions: [],
            currentQuestion: 0,
            score: 0,
            selectedAnswer: null,
            containerId: uniqueId,
            postId: container.dataset.postId,
            contentTitle: container.dataset.contentTitle
        };

        window[`quizState_${uniqueId}`] = quizState;

        const startButton = container.querySelector('.gemini-quiz-start-button');
        if (startButton) {
            startButton.onclick = () => startQuiz(quizState);
        }
    });
});

function startQuiz(state) {
    console.log(`Start quiz clicked for ${state.containerId}`);
    const container = document.getElementById(state.containerId);

    // Show loading
    container.innerHTML = `
        <div class="quiz-card">
            <div class="quiz-header">
                <h2>🤖 Creating Your Quiz...</h2>
            </div>
            <div style="text-align: center; padding: 40px 20px;">
                <div style="font-size: clamp(16px, 4vw, 20px); margin-bottom: 20px; color: #333; font-weight: 600;">Analyzing content...</div>
                <div style="width: 100%; background: #f0f0f0; height: 12px; border-radius: 6px; overflow: hidden; margin: 20px 0;">
                    <div style="width: 60%; height: 100%; background: linear-gradient(90deg, #de200b, #b91c0c); animation: pulse 2s infinite; border-radius: 6px;"></div>
                </div>
                <div style="font-size: clamp(14px, 3vw, 16px); color: #666; margin-top: 20px;">This may take a few moments...</div>
            </div>
        </div>
    `;

    const content = getPageContent(state);
    console.log("Content length:", content.length);

    fetch("/wp-json/quiz-generator/v1/generate", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
        },
        body: JSON.stringify({
            article: content,
            postId: state.postId
        })
    })
    .then(response => response.json())
    .then(data => {
        console.log("API response:", data);
        if (data.quiz && data.quiz.length > 0) {
            state.questions = data.quiz;
            state.state = "playing";
            state.currentQuestion = 0;
            state.score = 0;
            state.selectedAnswer = null;
            showQuestion(state);
        } else {
            showError(state, "No quiz data received from API.");
        }
    })
    .catch(error => {
        console.error("Quiz generation failed:", error);
        showError(state, "Failed to generate quiz. Please try again.");
    });
}

function getPageContent(state) {
    const selectors = [".entry-content", ".post-content", ".page-content", "main", "article"];
    for (let selector of selectors) {
        const element = document.querySelector(selector);
        if (element) {
            const clone = element.cloneNode(true);
            clone.querySelectorAll('.gemini-quiz-container, script, style, nav, .sidebar, .comments').forEach(el => el.remove());
            const text = clone.innerText.trim();
            if (text.length > 100) {
                return text;
            }
        }
    }
    return document.body.innerText.trim();
}

function showQuestion(state) {
    const container = document.getElementById(state.containerId);
    const question = state.questions[state.currentQuestion];
    const progress = ((state.currentQuestion + 1) / state.questions.length) * 100;

    let optionsHtml = "";
    question.options.forEach((option, index) => {
        let buttonClass = 'gemini-quiz-option-button';
        let disabled = '';
        if (state.selectedAnswer !== null) {
            disabled = 'disabled';
            if (index === question.correctAnswer) {
                buttonClass += ' correct';
            } else if (index === state.selectedAnswer) {
                buttonClass += ' incorrect';
            }
        }
        optionsHtml += `<button class="${buttonClass}" onclick='selectAnswer(window.quizState_${state.containerId}, ${index})' ${disabled}>${option}</button>`;
    });

    let explanationHtml = "";
    let nextButtonHtml = "";
    if (state.selectedAnswer !== null) {
        explanationHtml = `
            <div class="gemini-quiz-explanation">
                <strong>Explanation:</strong>
                <span>${question.explanation}</span>
            </div>
        `;
        const nextText = state.currentQuestion < state.questions.length - 1 ? "Next Question" : "View Results";
        nextButtonHtml = `
            <div style="text-align: center; margin-top: 24px;">
                <button class="gemini-quiz-next-button" onclick='nextQuestion(window.quizState_${state.containerId})'>${nextText}</button>
            </div>
        `;
    }

    container.innerHTML = `
        <div class="quiz-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
                <span style="font-weight: 700; color: #de200b; font-size: clamp(14px, 3.5vw, 16px);">Question ${state.currentQuestion + 1} of ${state.questions.length}</span>
                <span style="color: #666; font-size: clamp(14px, 3.5vw, 16px); font-weight: 600;">Score: ${state.score}/${state.currentQuestion}</span>
            </div>
            <div style="background: #f0f0f0; height: 12px; border-radius: 6px; margin: 20px 0; overflow: hidden;">
                <div style="background: linear-gradient(90deg, #de200b, #b91c0c); height: 100%; width: ${progress}%; transition: width 0.5s ease; border-radius: 6px;"></div>
            </div>
            <h3 style="margin: 24px 0 20px 0; font-size: clamp(18px, 4.5vw, 24px); line-height: 1.4; color: #333; font-weight: 700;">${question.question}</h3>
            <div style="margin: 20px 0;">
                ${optionsHtml}
            </div>
            ${explanationHtml}
            ${nextButtonHtml}
        </div>
    `;
}

function selectAnswer(state, answerIndex) {
    if (state.selectedAnswer !== null) return;
    state.selectedAnswer = answerIndex;
    if (answerIndex === state.questions[state.currentQuestion].correctAnswer) {
        state.score++;
    }
    showQuestion(state);
}

function nextQuestion(state) {
    if (state.currentQuestion < state.questions.length - 1) {
        state.currentQuestion++;
        state.selectedAnswer = null;
        showQuestion(state);
    } else {
        state.state = "results";
        showResults(state);
    }
}

function showResults(state) {
    const container = document.getElementById(state.containerId);
    const percentage = Math.round((state.score / state.questions.length) * 100);
    let message = "";
    let emoji = "";

    if (percentage >= 80) {
        message = "Excellent! You really know your stuff!";
        emoji = "🎉";
    } else if (percentage >= 60) {
        message = "Good job! You have a solid understanding.";
        emoji = "👍";
    } else if (percentage >= 40) {
        message = "Not bad! Consider reviewing the material.";
        emoji = "📚";
    } else {
        message = "Keep learning! Practice makes perfect.";
        emoji = "💪";
    }

    container.innerHTML = `
        <div class="quiz-card">
            <div style="text-align: center; padding: 32px 20px;">
                <div style="font-size: clamp(40px, 8vw, 60px); margin-bottom: 20px;">${emoji}</div>
                <h2 style="margin: 0 0 20px 0; color: #de200b; font-size: clamp(24px, 5vw, 32px); font-weight: 700;">Quiz Complete!</h2>
                <div style="font-size: clamp(36px, 8vw, 56px); font-weight: 800; color: #de200b; margin: 20px 0; text-shadow: 0 2px 4px rgba(222, 32, 11, 0.2);">${state.score}/${state.questions.length}</div>
                <div style="font-size: clamp(20px, 4vw, 24px); margin: 16px 0; color: #666; font-weight: 600;">${percentage}% Correct</div>
                <div style="font-size: clamp(16px, 3.5vw, 18px); margin: 24px 0; color: #333; line-height: 1.6; font-weight: 500;">${message}</div>
                <button class="gemini-quiz-reset-button" onclick='resetQuiz(window.quizState_${state.containerId})'>Take Quiz Again</button>
            </div>
        </div>
    `;
}

function showError(state, message) {
    const container = document.getElementById(state.containerId);
    container.innerHTML = `
        <div class="quiz-card">
            <div style="text-align: center; padding: 40px 20px; color: #dc3545;">
                <div style="font-size: clamp(40px, 8vw, 60px); margin-bottom: 20px;">⚠️</div>
                <h3 style="margin: 0 0 20px 0; font-size: clamp(20px, 4vw, 24px); color: #dc3545;">Quiz Generation Failed</h3>
                <p style="margin: 0 0 24px 0; font-size: clamp(16px, 3.5vw, 18px); line-height: 1.6;">${message}</p>
                <button class="gemini-quiz-reset-button" onclick='resetQuiz(window.quizState_${state.containerId})'>Try Again</button>
            </div>
        </div>
    `;
}

function resetQuiz(state) {
    const container = document.getElementById(state.containerId);
    state.state = "config";
    state.questions = [];
    state.currentQuestion = 0;
    state.score = 0;
    state.selectedAnswer = null;

    container.innerHTML = `
        <div class="quiz-card">
            <div class="quiz-header">
                <h2>🎯 Quiz Generator</h2>
                <p>Test your knowledge of ${state.contentTitle}</p>
            </div>
            <div style="text-align: center; padding: 0 10px;">
                <p style="margin: 20px 0; font-size: clamp(15px, 3.5vw, 18px); color: #333; line-height: 1.6;">
                    Ready to test your understanding? I'll create a personalized quiz based on the content of this page.
                </p>
                <button class="gemini-quiz-start-button" onclick='startQuiz(window.quizState_${state.containerId})'>Generate Quiz</button>
            </div>
        </div>
    `;
}