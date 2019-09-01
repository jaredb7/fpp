/*
 *   Effects handler for Falcon Player (FPP)
 *
 *   Copyright (C) 2013-2018 the Falcon Player Developers
 *      Initial development by:
 *      - David Pitts (dpitts)
 *      - Tony Mace (MyKroFt)
 *      - Mathew Mrosko (Materdaddy)
 *      - Chris Pinkham (CaptainMurdoch)
 *      For additional credits and developers, see credits.php.
 *
 *   The Falcon Player (FPP) is free software; you can redistribute it
 *   and/or modify it under the terms of the GNU General Public License
 *   as published by the Free Software Foundation; either version 2 of
 *   the License, or (at your option) any later version.
 *
 *   This program is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU General Public License for more details.
 *
 *   You should have received a copy of the GNU General Public License
 *   along with this program; if not, see <http://www.gnu.org/licenses/>.
 */

#include <string>
#include <mutex>
#include <array>
#include <string.h>

#include "common.h"
#include "effects.h"
#include "log.h"
#include "Sequence.h"
#include "settings.h"
#include "channeloutput/channeloutputthread.h"
#include "fseq/FSEQFile.h"


#define MAX_EFFECTS 100

class FPPeffect {
public:
    FPPeffect() : fp(nullptr), currentFrame(0) {}
    ~FPPeffect() { if (fp) delete fp; }
    
    std::string name;
    V2FSEQFile *fp;
    int       loop;
    int       background;
    uint32_t  currentFrame;
};

static int        effectCount = 0;
static int        pauseBackgroundEffects = 0;
static std::array<FPPeffect*, MAX_EFFECTS> effects;
static std::mutex effectsLock;

/*
 * Initialize effects constructs
 */
int InitEffects(void)
{
    std::string localFilename = getEffectDirectory();
    localFilename += "/background.eseq";

	if ((getFPPmode() == REMOTE_MODE) &&
		CheckForHostSpecificFile(getSetting("HostName"), localFilename)) {
        localFilename = "background_";
		localFilename += getSetting("HostName");

		LogInfo(VB_EFFECT, "Automatically starting background effect "
			"sequence %s\n", localFilename.c_str());

		StartEffect(localFilename.c_str(), 0, 1);
	} else if (FileExists(localFilename)) {
		LogInfo(VB_EFFECT, "Automatically starting background effect sequence "
			"background.eseq\n");
		StartEffect("background", 0, 1);
	}

	pauseBackgroundEffects = getSettingInt("pauseBackgroundEffects");
	return 1;
}

/*
 * Close effects constructs
 */
void CloseEffects(void)
{
}

/*
 * Get next available effect ID
 *
 * Assumes effectsLock is held already
 */
int GetNextEffectID(void)
{
	int i = -1;

	for (i = 0; i < MAX_EFFECTS; i++) {
		if (!effects[i])
			return i;
	}

	return -1;
}

/*
 * Check to see if any effects are running
 */
int IsEffectRunning(void)
{
	int result = 0;
    std::unique_lock<std::mutex> lock(effectsLock);
	result = effectCount;
	return result;
}

/*
 * Start a new effect offset at the specified channel number
 */
int StartEffect(const std::string &effectName, int startChannel, int loop)
{
	int   effectID = -1;
    int   frameTime = 50;
	LogInfo(VB_EFFECT, "Starting effect %s at channel %d\n", effectName.c_str(), startChannel);

    std::unique_lock<std::mutex> lock(effectsLock);
	if (effectCount >= MAX_EFFECTS) {
		LogErr(VB_EFFECT, "Unable to start effect %s, maximum number of effects already running\n", effectName.c_str());
		return effectID;
	}

    std::string filename = getEffectDirectory();
    filename += "/";
    filename += effectName;
    filename += ".eseq";

    FSEQFile *fseq = FSEQFile::openFSEQFile(filename);
	if (!fseq) {
		LogErr(VB_EFFECT, "Unable to open effect: %s\n", filename.c_str());
		return effectID;
	}
    V2FSEQFile *v2fseq = dynamic_cast<V2FSEQFile*>(fseq);
    if (!v2fseq) {
        delete fseq;
        LogErr(VB_EFFECT, "Effect file not a correct eseq file: %s\n", filename.c_str());
        return effectID;
    }

	if (v2fseq->m_sparseRanges.size() == 0){
		LogErr(VB_EFFECT, "eseq file must have at least one model range.");
        delete fseq;
		return effectID;
	}

	if (startChannel != 0) {
		// This will need to change if/when we support multiple models per file
        v2fseq->m_sparseRanges[0].first = startChannel - 1;
	}
    frameTime = v2fseq->getStepTime();
	effectID = GetNextEffectID();

	if (effectID < 0) {
		LogErr(VB_EFFECT, "Unable to start effect %s, unable to determine next effect ID\n", effectName.c_str());
        delete fseq;
		return effectID;
	}

	effects[effectID] = new FPPeffect;
	effects[effectID]->name = effectName;
	effects[effectID]->fp = v2fseq;
	effects[effectID]->loop = loop;
	effects[effectID]->background = 0;

	if (effectName == "background") {
		effects[effectID]->background = 1;
	} else if ((getFPPmode() == REMOTE_MODE) &&
			 (effectName.find("background_") == 0)) {
        std::string localFilename = "background_";
		localFilename += getSetting("HostName");

        if (localFilename == effectName) {
			effects[effectID]->background = 1;
        }
	}
	effectCount++;
    int tmpec = effectCount;
    lock.unlock();

	StartChannelOutputThread();
    
    if (!sequence->IsSequenceRunning()
        && tmpec == 1) {
        //first effect running, no sequence running, set the refresh rate
        //to the rate of the effect
        SetChannelOutputRefreshRate(1000 / frameTime);
    }


	return effectID;
}

/*
 * Helper function to stop an effect, assumes effectsLock is already held
 */
void StopEffectHelper(int effectID)
{
	FPPeffect *e = NULL;
	e = effects[effectID];
    delete e;
	effects[effectID] = NULL;
	effectCount--;
}

/*
 * Stop all effects named effectName
 */
int StopEffect(const std::string &effectName)
{
	LogDebug(VB_EFFECT, "StopEffect(%s)\n", effectName.c_str());

    std::unique_lock<std::mutex> lock(effectsLock);
	for (int i = 0; i < MAX_EFFECTS; i++) {
		if (effects[i] && effects[i]->name == effectName)
			StopEffectHelper(i);
	}
    lock.unlock();

	if ((!IsEffectRunning()) &&
		(!sequence->IsSequenceRunning()))
		sequence->SendBlankingData();

	return 1;
}

/*
 * Stop a single effect
 */
int StopEffect(int effectID)
{
	FPPeffect *e = NULL;

	LogDebug(VB_EFFECT, "StopEffect(%d)\n", effectID);

    std::unique_lock<std::mutex> lock(effectsLock);
	if (!effects[effectID]) {
		return 0;
	}

	StopEffectHelper(effectID);
    lock.unlock();

	if ((!IsEffectRunning()) &&
		(!sequence->IsSequenceRunning()))
		sequence->SendBlankingData();

	return 1;
}

/*
 * Stop all effects
 */
void StopAllEffects(void)
{
	int i;

	LogDebug(VB_EFFECT, "Stopping all effects\n");

    std::unique_lock<std::mutex> lock(effectsLock);

	for (i = 0; i < MAX_EFFECTS; i++) {
		if (effects[i])
			StopEffectHelper(i);
	}
    lock.unlock();
    if ((!IsEffectRunning()) &&
        (!sequence->IsSequenceRunning()))
        sequence->SendBlankingData();
}

/*
 * Overlay a single effect onto raw channel data
 */
int OverlayEffect(int effectID, char *channelData)
{
	FPPeffect *e = NULL;
	if (!effects[effectID]) {
		LogErr(VB_EFFECT, "Invalid Effect ID %d\n", effectID);
		return 0;
	}

	e = effects[effectID];
    FSEQFile::FrameData *d = e->fp->getFrame(e->currentFrame);
    if (d == nullptr && e->loop) {
        e->currentFrame = 0;
        d = e->fp->getFrame(e->currentFrame);
    }
    e->currentFrame++;
    if (d) {
        d->readFrame((uint8_t*)channelData, FPPD_MAX_CHANNELS);
        delete d;
        return 1;
    }
    else
        StopEffectHelper(effectID);

    return 0;
}

/*
 * Overlay current effects onto raw channel data
 */
int OverlayEffects(char *channelData)
{
	int  i;
	int  dataRead = 0;

    std::unique_lock<std::mutex> lock(effectsLock);

	if (effectCount == 0) {
		return 0;
	}

	int skipBackground = 0;
    if (pauseBackgroundEffects && sequence->IsSequenceRunning()) {
		skipBackground = 1;
    }

	for (i = 0; i < MAX_EFFECTS; i++) {
		if (effects[i]) {
			if ((!skipBackground) ||
                (skipBackground && (!effects[i]->background))) {
				dataRead |= OverlayEffect(i, channelData);
            }
		}
	}

    lock.unlock();

	if ((dataRead == 0) &&
		(!IsEffectRunning()) &&
        (!sequence->IsSequenceRunning())) {
		sequence->SendBlankingData();
    }

	return 1;
}

/*
 * Get list of running effects and their IDs
 *
 * Format: [EFFECTID1,EFFECTNAME1[,EFFECTID2,EFFECTNAME2]...]
 *
 * NOTE: Caller is responsible for freeing string allocated
 */
int GetRunningEffects(char *msg, char **result)
{
	int length = strlen(msg) + 2; // 1 for LF, 1 for NULL termination
	int i = 0;

    std::unique_lock<std::mutex> lock(effectsLock);

	for (i = 0; i < MAX_EFFECTS; i++) {
		if (effects[i]) {
			// delimiters
			length += 2;

			// ID
			length++;
			if (i > 9)
				length++;
			if (i > 99)
				length++;

			// Name
			length += strlen(effects[i]->name.c_str());
		}
	}

	*result = (char *)malloc(length);
	char *cptr = *result;
	*cptr = '\0';

	strcat(cptr, msg);
	cptr += strlen(msg);

	for (i = 0; i < MAX_EFFECTS; i++) {
		if (effects[i]) {
			strcat(cptr,";");
			cptr++;

			cptr += snprintf(cptr, 4, "%d", i);

			strcat(cptr, ",");
			cptr++;

			strcat(cptr, effects[i]->name.c_str());
			cptr += strlen(effects[i]->name.c_str());
		}
	}

	strcat(cptr, "\n");
	return strlen(*result);
}

